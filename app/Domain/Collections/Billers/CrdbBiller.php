<?php

namespace App\Domain\Collections\Billers;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\ControlNumber;
use App\Domain\Collections\Models\InboundEvent;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Services\PaymentRecorder;
use App\Domain\Identity\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CRDB "standard bills" API (Integration guide for standard bills API, CRDB ESB,
 * signature version of 09 May 2026). CRDB is the caller; NguzoPay is the institution.
 *
 *  verify: paymentReference (our control number) → payer, amount, amountType
 *  post:   credit advice for a completed payment → our receipt number
 *
 * Every request carries token = Base64(SHA256withRSA(signing string)) made with
 * CRDB's private key, and checksum = SHA1(token + MD5(paymentReference)).
 */
class CrdbBiller
{
    public const PROVIDER = 'crdb';

    public function __construct(private readonly BillLookup $lookup, private readonly PaymentRecorder $recorder) {}

    /** One URL may serve both calls (guide §4): a payment carries transactionRef. */
    public function handle(string $raw): StandardBillsResult
    {
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return StandardBillsResult::error(StandardBillsResult::INVALID_REFERENCE, 'Malformed request');
        }

        return isset($data['transactionRef']) ? $this->post($raw, $data) : $this->verify($raw, $data);
    }

    public function verify(string $raw, array $data): StandardBillsResult
    {
        $signing = $this->text($raw, $data, ['institutionID', 'institutionId']).$this->text($raw, $data, ['paymentReference']);
        if ($error = $this->authenticate($raw, $data, $signing)) {
            return $error;
        }

        [$control, $error] = $this->resolve((string) ($data['paymentReference'] ?? ''));
        if ($error) {
            return $error;
        }

        $bill = $this->bill($control);
        if ($control->invoice && $bill['amount'] === 0) {
            return StandardBillsResult::error(StandardBillsResult::ALREADY_PAID, 'Payment reference number already paid');
        }

        return StandardBillsResult::ok($bill);
    }

    public function post(string $raw, array $data): StandardBillsResult
    {
        $signing = implode('', [
            $this->text($raw, $data, ['payerName']),
            $this->text($raw, $data, ['payerId', 'payerID']),
            $this->text($raw, $data, ['amount']),
            $this->text($raw, $data, ['currency']),
            $this->text($raw, $data, ['amountType']),
            $this->text($raw, $data, ['paymentDesc']),
            $this->text($raw, $data, ['paymentType']),
            $this->text($raw, $data, ['transactionRef']),
            $this->text($raw, $data, ['transactionChannel']),
            $this->text($raw, $data, ['payerMobile']),
            $this->text($raw, $data, ['paymentReference']),
            $this->text($raw, $data, ['institutionId', 'institutionID']),
        ]);
        if ($error = $this->authenticate($raw, $data, $signing)) {
            return $error;
        }

        $transactionRef = trim((string) $data['transactionRef']);
        if ($duplicate = $this->duplicate($transactionRef)) {
            return $duplicate;
        }

        if (strtoupper((string) ($data['currency'] ?? 'TZS')) !== 'TZS') {
            return StandardBillsResult::error(StandardBillsResult::INVALID_REFERENCE, 'Only TZS payments are accepted');
        }

        $amount = (int) round((float) ($data['amount'] ?? 0));
        if ($amount <= 0) {
            return StandardBillsResult::error(StandardBillsResult::INVALID_REFERENCE, 'Invalid amount');
        }

        [$control, $error] = $this->resolve((string) ($data['paymentReference'] ?? ''));
        if ($error) {
            return $error;
        }

        try {
            $payment = DB::transaction(function () use ($control, $data, $transactionRef, $amount, $raw) {
                $invoice = $control->invoice; // null for a customer account number
                if ($invoice && ! $invoice->allow_partial && $invoice->status === InvoiceStatus::Paid) {
                    return null;
                }

                $payment = $this->recorder->record($control->business, $control->customer, [
                    'provider' => self::PROVIDER,
                    'provider_reference' => $transactionRef,
                    'control_number_id' => $control->id,
                    'channel' => 'bank',
                    'payer_phone' => $this->phone($data['payerMobile'] ?? null),
                    'payer_name' => mb_substr((string) ($data['payerName'] ?? ''), 0, 190) ?: null,
                    'amount' => $amount,
                    'details' => array_filter([
                        'bank' => 'CRDB',
                        'transaction_channel' => $data['transactionChannel'] ?? null, // IB | SIMAPP | AGENCY
                        'transaction_date' => $data['transactionDate'] ?? null,
                        'payment_type' => $data['paymentType'] ?? null,
                        'amount_type' => $data['amountType'] ?? null,
                        'payer_id' => $data['payerId'] ?? $data['payerID'] ?? null,
                    ]),
                ], null, $invoice, oldestFirst: $invoice === null);

                InboundEvent::create([
                    'provider' => self::PROVIDER,
                    'event_key' => $transactionRef,
                    'event_type' => 'bill.payment',
                    'signature_ok' => true,
                    'payload' => Arr::except(json_decode($raw, true), ['token', 'checksum']),
                    'status' => 'processed',
                    'processed_at' => now(),
                ]);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            // The same credit advice arrived twice at the same moment.
            return $this->duplicate($transactionRef) ?? StandardBillsResult::error(StandardBillsResult::DUPLICATE, 'Duplicate entry');
        }

        if (! $payment) {
            return StandardBillsResult::error(StandardBillsResult::TRANSACTION_ALREADY_PAID, 'Payment reference already paid');
        }

        return StandardBillsResult::ok(['receipt' => $payment->receipt_number]);
    }

    /** What the payer sees at the bank for this control number. */
    private function bill(ControlNumber $control): array
    {
        $customer = $control->customer;
        $invoice = $control->invoice;

        $amount = $this->lookup->outstanding($control);
        if ($invoice) {
            // FIXED: exact amount only; FLEXIBLE: up to the balance (guide §5.1).
            $type = $invoice->allow_partial ? 'FLEXIBLE' : 'FIXED';
        } else {
            // Nothing owed: the payer may still pay ahead (FULL = this amount or more).
            $type = $amount > 0 ? 'FLEXIBLE' : 'FULL';
        }

        return [
            'payerName' => $customer->name,
            'amount' => $amount,
            'amountType' => $type,
            'currency' => 'TZS',
            'paymentReference' => $control->number,
            'paymentType' => (string) config('nguzopay.payments.crdb.payment_type'),
            'paymentDesc' => $this->lookup->description($control),
            'payerID' => $customer->reference ?: $control->number,
        ];
    }

    /** @return array{0: ?ControlNumber, 1: ?StandardBillsResult} */
    private function resolve(string $reference): array
    {
        [$control, $reason] = $this->lookup->resolve($reference);

        return match ($reason) {
            null => [$control, null],
            'expired' => [null, StandardBillsResult::error(StandardBillsResult::EXPIRED, 'Payment reference number has expired')],
            default => [null, StandardBillsResult::error(StandardBillsResult::INVALID_REFERENCE, 'Invalid payment reference number')],
        };
    }

    private function duplicate(string $transactionRef): ?StandardBillsResult
    {
        $existing = Payment::where('provider', self::PROVIDER)->where('provider_reference', $transactionRef)->first();

        return $existing
            ? StandardBillsResult::error(StandardBillsResult::DUPLICATE, 'Duplicate entry', ['receipt' => $existing->receipt_number])
            : null;
    }

    private function authenticate(string $raw, array $data, string $signingString): ?StandardBillsResult
    {
        $token = (string) ($data['token'] ?? '');
        $reference = (string) ($data['paymentReference'] ?? '');
        $institution = $this->text($raw, $data, ['institutionID', 'institutionId']);

        if ($token === '' || $institution !== (string) config('nguzopay.payments.crdb.institution_id')) {
            return StandardBillsResult::error(StandardBillsResult::INVALID_TOKEN, 'Invalid token');
        }

        if (! hash_equals(sha1($token.md5($reference)), strtolower((string) ($data['checksum'] ?? '')))) {
            return StandardBillsResult::error(StandardBillsResult::INVALID_CHECKSUM, 'Invalid checksum');
        }

        $key = $this->publicKey();
        $signature = base64_decode($token, true);
        if (! $key || $signature === false || openssl_verify($signingString, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return StandardBillsResult::error(StandardBillsResult::INVALID_TOKEN, 'Invalid token');
        }

        return null;
    }

    private function publicKey(): mixed
    {
        $pem = config('nguzopay.payments.crdb.public_key');
        $path = config('nguzopay.payments.crdb.public_key_path');

        if (! $pem && $path && is_readable($path)) {
            $pem = file_get_contents($path);
        }
        if (! $pem) {
            Log::error('CRDB public key is not configured; every CRDB request is refused.');

            return null;
        }

        return openssl_pkey_get_public(str_replace('\n', "\n", (string) $pem)) ?: null;
    }

    /**
     * A field exactly as CRDB wrote it, for the signing string. Numbers are taken from
     * the raw JSON text (5 stays "5", 5000.00 stays "5000.00"); missing or null is "".
     */
    private function text(string $raw, array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }
            $value = $data[$key];
            if (is_string($value)) {
                return $value;
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if (preg_match('/"'.preg_quote($key, '/').'"\s*:\s*(-?[0-9][0-9.eE+-]*)/', $raw, $m)) {
                return $m[1];
            }

            return (string) $value;
        }

        return '';
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
