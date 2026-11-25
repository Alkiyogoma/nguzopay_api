<?php

namespace App\Domain\Collections\Billers;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\ControlNumber;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Collections\Models\InboundEvent;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Services\PaymentRecorder;
use App\Domain\Identity\Support\PhoneNumber;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * NMB "Biller Standard Integration API" (Payment: Detail Enquiry & Payment
 * Notification). NMB is the caller; NguzoPay is the biller. Response codes:
 * 600 success, 649 failure. Per NMB, a 649 on a payment notification makes NMB
 * auto-reverse the payment, so:
 *  - anything we did not record answers 649 (the payer gets the money back);
 *  - an exact replay of a notification we already recorded answers 600 with the
 *    original receipt (a 649 there would reverse money we have already booked).
 */
class NmbBiller
{
    public const PROVIDER = 'nmb';

    public const SUCCESS = 600;

    public const FAILURE = 649;

    public function __construct(private readonly BillLookup $lookup, private readonly PaymentRecorder $recorder) {}

    /** One URL may serve both: a notification carries requestDetails. */
    public function handle(array $data): array
    {
        return isset($data['requestDetails']) ? $this->notify($data) : $this->inquiry($data);
    }

    public function inquiry(array $data): array
    {
        $reference = trim((string) ($data['body']['paymentReference'] ?? ''));
        if ($reference === '' || empty($data['body']['transactionId'])) {
            return $this->failure('Failed to Process Enquiry Request');
        }

        [$control, $reason] = $this->lookup->resolve($reference);
        if (! $control) {
            return $this->failure($reason === 'expired' ? 'Bill has expired' : 'Failed to Process Enquiry Request');
        }

        $amount = $this->lookup->outstanding($control);
        if ($control->invoice && $amount === 0) {
            return $this->failure('Bill already paid');
        }

        [$option, $minimum] = $this->paymentRule($control, $amount);
        $customer = $control->customer;
        $expiry = $control->expires_at ?? now()->addDays((int) config('nguzopay.payments.nmb.bill_expiry_days'));

        return $this->success([
            'paymentReference' => $control->number,
            'billAmount' => (string) $amount,
            'billCurrency' => 'TZS',
            'paymentOption' => $option,
            'paymentPlan' => (string) config('nguzopay.payments.nmb.payment_plan'),
            'minAmountToPay' => (string) $minimum,
            'billDescription' => $this->lookup->description($control),
            'billAccountCode' => (string) config('nguzopay.payments.nmb.collection_account'),
            'accountNumber' => (string) config('nguzopay.payments.nmb.collection_account'),
            'billProductCode' => (string) config('nguzopay.payments.nmb.bill_product_code'),
            'productCode' => (string) config('nguzopay.payments.nmb.product_code'),
            'customerName' => $customer->name,
            'billOwner' => [
                'name' => $customer->name,
                'mobileNumber' => (string) $customer->phone,
                'emailAddress' => (string) $customer->email,
            ],
            // Zero-padded, as sadaka sends it to NMB in production: 01/05/2027 09:03:00 AM.
            'billExpirationDate' => $expiry->format('m/d/Y h:i:s A'),
            'billStatus' => ['Code' => '103', 'Description' => 'active'],
        ]);
    }

    public function notify(array $data): array
    {
        $d = (array) $data['requestDetails'];
        $transactionId = trim((string) ($d['transactionId'] ?? ''));
        $reference = trim((string) ($d['paymentReference'] ?? ''));
        $bankReference = trim((string) ($d['bankReference'] ?? ''));
        $amount = is_numeric($d['amount'] ?? null) ? (int) round((float) $d['amount']) : 0;

        if ($transactionId === '' || $reference === '' || $bankReference === '' || $amount <= 0) {
            return $this->failure('Invalid payment notification');
        }

        if ($replay = $this->replay($transactionId, $bankReference, $reference, $amount)) {
            return $replay;
        }

        [$control, $reason] = $this->lookup->resolve($reference);
        if (! $control) {
            return $this->failure($reason === 'expired' ? 'Bill has expired' : 'Payment Reference not found or inactive');
        }

        try {
            $payment = DB::transaction(function () use ($control, $d, $transactionId, $bankReference, $amount, $data) {
                // Read under the lock, so two notifications for one EXACT bill cannot both pass.
                $invoice = $control->invoice ? Invoice::whereKey($control->invoice->id)->lockForUpdate()->first() : null;
                if ($invoice && ! $invoice->allow_partial && ($invoice->status === InvoiceStatus::Paid || $invoice->balance() <= 0)) {
                    return null;
                }

                $payment = $this->recorder->record($control->business, $control->customer, [
                    'provider' => self::PROVIDER,
                    'provider_reference' => $transactionId,
                    'control_number_id' => $control->id,
                    'channel' => 'bank',
                    'payer_phone' => $this->phone($d['payerDetails']['mobileNumber'] ?? null),
                    'payer_name' => mb_substr((string) ($d['payerDetails']['name'] ?? ''), 0, 190) ?: null,
                    'amount' => $amount,
                    'paid_at' => $this->effectiveDate($d['effectiveDate'] ?? null),
                    'details' => array_filter([
                        'bank' => 'NMB',
                        'bank_reference' => $bankReference,
                        'channel' => $d['channelId'] ?? null,
                        'effective_date' => $d['effectiveDate'] ?? null,
                    ]),
                ], null, $invoice, oldestFirst: $invoice === null);

                InboundEvent::create([
                    'provider' => self::PROVIDER,
                    'event_key' => $transactionId,
                    'event_type' => 'bill.payment',
                    'signature_ok' => true,
                    'payload' => $data,
                    'status' => 'processed',
                    'processed_at' => now(),
                ]);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            // The same notification arrived twice at the same moment.
            return $this->replay($transactionId, $bankReference, $reference, $amount) ?? $this->failure('Duplicate transaction reference');
        } catch (Throwable $e) {
            report($e);

            return $this->failure('Payment processing failed'); // nothing was recorded: let NMB reverse it
        }

        if (! $payment) {
            return $this->failure('Already Paid');
        }

        return $this->receipt($payment, $transactionId, $reference);
    }

    /**
     * NMB option + minimum for this bill (NMB doc "paymentOption"):
     * EXACT = one payment of the exact amount; PARTIAL = instalments, the last one
     * covering the rest; INFINITY = any amount above zero (account with nothing owed).
     *
     * @return array{0: string, 1: int}
     */
    private function paymentRule(ControlNumber $control, int $amount): array
    {
        $floor = (int) config('nguzopay.payments.nmb.min_amount');
        $invoice = $control->invoice;

        if ($invoice && ! $invoice->allow_partial) {
            return ['EXACT', $amount];
        }
        if ($amount === 0) {
            return ['INFINITY', max(1, $floor)];
        }

        $minimum = max($floor, $invoice ? $invoice->minimumPayment() : 0);

        return ['PARTIAL', min($minimum, $amount)];
    }

    /**
     * A notification we already recorded. Same details: 600 with the original receipt.
     * Same transaction or bank reference with different details: 649 (NMB reverses it).
     */
    private function replay(string $transactionId, string $bankReference, string $reference, int $amount): ?array
    {
        $existing = Payment::where('provider', self::PROVIDER)
            ->where(fn ($q) => $q->where('provider_reference', $transactionId)->orWhere('details->bank_reference', $bankReference))
            ->with('controlNumber')->first();

        if (! $existing) {
            return null;
        }

        $same = $existing->provider_reference === $transactionId
            && ($existing->details['bank_reference'] ?? null) === $bankReference
            && $existing->amount === $amount
            && $existing->controlNumber?->number === preg_replace('/\D/', '', $reference);

        return $same ? $this->receipt($existing, $transactionId, $reference) : $this->failure('Duplicate transaction reference');
    }

    private function receipt(Payment $payment, string $transactionId, string $reference): array
    {
        return $this->success([
            'transactionId' => $transactionId,
            'receiptNumber' => $payment->receipt_number,
            'paymentReference' => $reference,
            'barcode' => $payment->receipt_number,
        ]);
    }

    private function success(array $body): array
    {
        return ['statusCode' => self::SUCCESS, 'message' => 'SUCCESS', 'body' => $body];
    }

    private function failure(string $message): array
    {
        return ['body' => (object) [], 'message' => $message, 'statusCode' => self::FAILURE];
    }

    /**
     * NMB's value date for the payment, so our records match NMB's statement.
     * Anything unparseable, in the future or older than 30 days falls back to now.
     * Back-dating is safe for settlements: unsettled payments roll into the next sweep.
     */
    private function effectiveDate(mixed $value): CarbonInterface
    {
        try {
            $date = is_string($value) && trim($value) !== '' ? Carbon::parse($value, 'Africa/Dar_es_Salaam') : null;
        } catch (Throwable) {
            $date = null;
        }

        return $date && $date->lte(now()->addMinutes(5)) && $date->gte(now()->subDays(30)) ? $date->utc() : now();
    }

    private function phone(mixed $value): ?string
    {
        try {
            return $value ? PhoneNumber::normalize((string) $value) : null;
        } catch (Throwable) {
            return mb_substr((string) $value, 0, 20);
        }
    }
}
