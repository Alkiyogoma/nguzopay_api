<?php

namespace App\Domain\Collections\Billers;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\ControlNumber;
use App\Domain\Billing\Services\ControlNumberService;

/**
 * What a bank needs to know about a control number, independent of the bank's
 * wire format. Each bank integration (CRDB, NMB) maps this to its own protocol.
 */
class BillLookup
{
    public function __construct(private readonly ControlNumberService $controlNumbers) {}

    /**
     * @return array{0: ?ControlNumber, 1: ?string} the control number, or a reason: invalid | expired
     */
    public function resolve(string $reference): array
    {
        $control = $this->controlNumbers->find($reference);
        $control?->load('business', 'customer', 'invoice');

        if (! $control || $control->status !== 'active' || ! $control->business?->canCollect()
            || $control->invoice?->status === InvoiceStatus::Void) {
            return [null, 'invalid'];
        }
        if ($control->expires_at && $control->expires_at->isPast()) {
            return [null, 'expired'];
        }

        return [$control, null];
    }

    /** Amount owed on the reference now: the invoice balance, or all of the customer's open invoices. */
    public function outstanding(ControlNumber $control): int
    {
        if ($control->invoice) {
            return $control->invoice->balance();
        }

        return (int) $control->customer->invoices()
            ->whereIn('status', [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid])
            ->selectRaw('coalesce(sum(amount_due - amount_paid), 0) as outstanding')->value('outstanding');
    }

    public function description(ControlNumber $control): string
    {
        return mb_substr($control->business->displayName().' : '.$control->number.' : '.($control->invoice?->number ?? 'Account'), 0, 100);
    }
}
