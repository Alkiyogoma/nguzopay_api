<?php

namespace App\Domain\Collections\Reports;

use App\Domain\Business\Models\Business;
use App\Domain\Payouts\Models\Payout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** A business's withdrawals (D-027). */
class PayoutsReport extends Report
{
    protected const STATUS = ['requested' => 'Requested', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];

    public function title(): string
    {
        return 'Withdrawals';
    }

    protected function base(?Business $business): Builder
    {
        return Payout::where('payouts.business_id', $business->id)->with('requestedBy')->latest();
    }

    protected function dateColumn(): string
    {
        return 'payouts.created_at';
    }

    protected function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(array_keys(self::STATUS))],
            'channel' => ['nullable', Rule::in(['mobile_money', 'bank'])],
        ];
    }

    protected function filter(Builder $query, array $f): void
    {
        if (! empty($f['status'])) {
            $query->where('payouts.status', $f['status']);
        }
        if (! empty($f['channel'])) {
            $query->where('payouts.channel', $f['channel']);
        }
        if (! empty($f['q'])) {
            $like = self::like($f['q']);
            $query->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('external_reference', 'like', $like)
                ->orWhere('recipient_name', 'like', $like)->orWhere('narration', 'like', $like));
        }
    }

    protected function describe(array $f): array
    {
        return array_values(array_filter([
            ! empty($f['status']) ? 'Status: '.self::STATUS[$f['status']] : null,
            ! empty($f['channel']) ? 'Channel: '.($f['channel'] === 'bank' ? 'Bank' : 'Mobile money') : null,
        ]));
    }

    public function summary(Builder $filtered, ?Business $business): array
    {
        $q = fn () => (clone $filtered)->reorder();

        return [
            self::card('withdrawn', 'Withdrawn', (int) $q()->where('payouts.status', 'completed')->sum('amount'), 'money'),
            self::card('count', 'Completed', $q()->where('payouts.status', 'completed')->count()),
            self::card('open', 'In progress', (int) $q()->whereIn('payouts.status', Payout::OPEN)->sum('amount'), 'money'),
            self::card('failed', 'Failed', $q()->where('payouts.status', 'failed')->count()),
        ];
    }

    public function columns(): array
    {
        return [
            'date' => ['Date', 'datetime'],
            'reference' => ['Reference', 'text'],
            'recipient' => ['Recipient', 'text'],
            'account' => ['Account', 'text'],
            'channel' => ['Channel', 'text'],
            'amount' => ['Amount', 'money'],
            'fee' => ['Fee', 'money'],
            'narration' => ['Narration', 'text'],
            'status' => ['Status', 'text'],
            'transfer' => ['Transfer reference', 'text'],
        ];
    }

    /** @param  Payout  $p */
    public function row(Model $p): array
    {
        return [
            'date' => $p->created_at,
            'reference' => $p->reference,
            'recipient' => $p->recipient_name,
            'account' => $p->maskedAccount(),
            'channel' => $p->channel === 'bank' ? ($p->bank_name ?: 'Bank') : ($p->operator?->label() ?? 'Mobile money'),
            'amount' => $p->amount,
            'fee' => $p->fee,
            'narration' => $p->narration,
            'status' => self::STATUS[$p->status] ?? $p->status,
            'transfer' => $p->external_reference,
        ];
    }
}
