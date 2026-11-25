<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\ControlNumber;
use App\Domain\Billing\Models\Customer;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Business\Models\Business;
use Illuminate\Support\Facades\DB;

/**
 * Control numbers are all digits, so payers can key them on any phone menu
 * (SIM banking, USSD, agent terminals): 4-digit merchant code + 6-digit
 * sequence + Luhn check digit, e.g. 1001 000457 3 → "10010004573".
 * The check digit catches a mistyped digit before the bank asks us (D-018).
 */
class ControlNumberService
{
    public function forInvoice(Invoice $invoice): ControlNumber
    {
        return ControlNumber::where('invoice_id', $invoice->id)->first()
            ?? $this->issue($invoice->business_id, ['type' => 'invoice', 'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id]);
    }

    /** The customer's permanent account number: always resolves to the outstanding balance. */
    public function forCustomer(Customer $customer): ControlNumber
    {
        return ControlNumber::where('customer_id', $customer->id)->where('type', 'customer')->first()
            ?? $this->issue($customer->business_id, ['type' => 'customer', 'customer_id' => $customer->id]);
    }

    public function find(string $number): ?ControlNumber
    {
        $number = preg_replace('/\D/', '', $number);

        return self::isValid($number) ? ControlNumber::where('number', $number)->first() : null;
    }

    public static function isValid(string $number): bool
    {
        return (bool) preg_match('/^\d{11}$/', $number) && self::luhn(substr($number, 0, -1)) === (int) substr($number, -1);
    }

    public static function luhn(string $digits): int
    {
        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $i => $d) {
            $d = (int) $d * ($i % 2 === 0 ? 2 : 1);
            $sum += $d > 9 ? $d - 9 : $d;
        }

        return (10 - $sum % 10) % 10;
    }

    private function issue(string $businessId, array $attributes): ControlNumber
    {
        return DB::transaction(function () use ($businessId, $attributes) {
            $business = Business::whereKey($businessId)->lockForUpdate()->firstOrFail();
            $sequence = $business->next_control_sequence;
            Business::whereKey($businessId)->increment('next_control_sequence');

            $base = str_pad((string) $business->code, 4, '0', STR_PAD_LEFT).str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

            return ControlNumber::create($attributes + [
                'business_id' => $businessId,
                'number' => $base.self::luhn($base),
            ]);
        });
    }
}
