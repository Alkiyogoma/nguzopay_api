<?php

namespace App\Domain\Collections\Reports;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\PaymentIntent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class RequestsReport extends Report
{
    private const STATUS = ['pending' => 'Pending', 'processing' => 'Waiting for payer', 'succeeded' => 'Paid', 'failed' => 'Failed', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];

    public function title(): string
    {
        return 'Payment requests';
    }

    protected function base(Business $business): Builder
    {
        return PaymentIntent::where('payment_intents.business_id', $business->id)->with('customer', 'invoice')->latest();
    }

    protected function dateColumn(): string
    {
        return 'payment_intents.created_at';
    }

    protected function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in([...array_keys(self::STATUS), 'waiting'])],
            'invoice_id' => ['nullable', 'string'],
        ];
    }

    protected function filter(Builder $query, array $f): void
    {
        match ($f['status'] ?? null) {
            null => null,
            'waiting' => $query->whereIn('status', ['pending', 'processing']),
            default => $query->where('status', $f['status']),
        };
        if (! empty($f['invoice_id'])) {
            $query->where('invoice_id', $f['invoice_id']);
        }
        if (! empty($f['q'])) {
            $like = self::like($f['q']);
            $query->where(fn ($w) => $w->where('payer_phone', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like))
                ->orWhereHas('invoice', fn ($i) => $i->where('number', 'like', $like)));
        }
    }

    protected function describe(array $f): array
    {
        return isset($f['status']) ? ['Status: '.(self::STATUS[$f['status']] ?? 'Waiting')] : [];
    }

    public function summary(Builder $filtered, Business $business): array
    {
        $q = fn () => (clone $filtered)->reorder();
        $finished = $q()->whereIn('status', ['succeeded', 'failed', 'expired'])->count();
        $paid = $q()->where('status', 'succeeded');

        return [
            self::card('sent', 'Requests sent', $q()->count()),
            self::card('paid', 'Paid', (int) (clone $paid)->sum('amount'), 'money'),
            self::card('success', 'Success rate', $finished ? round((clone $paid)->count() / $finished * 100, 1) : 0, 'percent'),
            self::card('waiting', 'Waiting for payers', $q()->whereIn('status', ['pending', 'processing'])->count()),
        ];
    }

    public function columns(): array
    {
        return [
            'sent' => ['Sent', 'datetime'],
            'customer' => ['Customer', 'text'],
            'invoice' => ['Invoice', 'text'],
            'phone' => ['Phone', 'text'],
            'network' => ['Network', 'text'],
            'amount' => ['Amount', 'money'],
            'status' => ['Status', 'text'],
            'reason' => ['Reason', 'text'],
        ];
    }

    /** @param  PaymentIntent  $i */
    public function row(Model $i): array
    {
        return [
            'sent' => $i->created_at,
            'customer' => $i->customer?->name,
            'invoice' => $i->invoice?->number ?? 'Credit',
            'phone' => $i->payer_phone,
            'network' => $i->operator?->label(),
            'amount' => $i->amount,
            'status' => self::STATUS[$i->status->value],
            'reason' => $i->failure_reason,
        ];
    }
}
