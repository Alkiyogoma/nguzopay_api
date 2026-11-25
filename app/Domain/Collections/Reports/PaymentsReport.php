<?php

namespace App\Domain\Collections\Reports;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class PaymentsReport extends Report
{
    private const PROVIDERS = ['mock' => 'Test network', 'snippe' => 'Snippe', 'azampay' => 'Azampay', 'selcom' => 'Selcom', 'crdb' => 'CRDB Bank', 'nmb' => 'NMB Bank', 'manual' => 'Recorded by hand'];

    private const METHOD_PROVIDERS = [
        'mobile_money' => ['mock', 'snippe', 'azampay', 'selcom'],
        'bank' => ['crdb', 'nmb'],
        'manual' => ['manual'],
    ];

    public function title(): string
    {
        return 'Payments';
    }

    protected function base(?Business $business): Builder
    {
        return Payment::where('payments.business_id', $business->id)->with('customer.group')->latest('paid_at');
    }

    protected function dateColumn(): string
    {
        return 'payments.paid_at';
    }

    protected function rules(): array
    {
        return [
            'method' => ['nullable', Rule::in(array_keys(self::METHOD_PROVIDERS))],
            'status' => ['nullable', Rule::in(['succeeded', 'voided'])],
            'provider' => ['nullable', Rule::in(array_keys(self::PROVIDERS))],
            'customer_id' => ['nullable', 'string'],
            'group_id' => ['nullable', 'string'],
        ];
    }

    protected function filter(Builder $query, array $f): void
    {
        if (! empty($f['method'])) {
            $query->whereIn('provider', self::METHOD_PROVIDERS[$f['method']]);
        }
        if (! empty($f['status'])) {
            $query->where('payments.status', $f['status']);
        }
        if (! empty($f['provider'])) {
            $query->where('provider', $f['provider']);
        }
        if (! empty($f['customer_id'])) {
            $query->where('customer_id', $f['customer_id']);
        }
        GroupFilter::apply($query, $f);
        if (! empty($f['q'])) {
            $like = self::like($f['q']);
            $query->where(fn ($w) => $w->where('receipt_number', 'like', $like)->orWhere('provider_reference', 'like', $like)
                ->orWhere('payer_phone', 'like', $like)->orWhere('payer_name', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('reference', 'like', $like)));
        }
    }

    protected function describe(array $f): array
    {
        return array_values(array_filter([
            isset($f['method']) ? 'Method: '.['bank' => 'Bank', 'mobile_money' => 'Mobile money', 'manual' => 'Recorded by hand'][$f['method']] : null,
            isset($f['status']) ? 'Status: '.($f['status'] === 'voided' ? 'Voided' : 'Received') : null,
            GroupFilter::label($f),
            isset($f['provider']) ? 'Provider: '.self::PROVIDERS[$f['provider']] : null,
        ]));
    }

    public function summary(Builder $filtered, ?Business $business): array
    {
        // Voided entries are listed but never counted.
        $q = fn () => (clone $filtered)->reorder()->where('payments.status', 'succeeded');

        return [
            self::card('received', 'Received', (int) $q()->sum('amount'), 'money'),
            self::card('count', 'Payments', $q()->count()),
            self::card('fees', 'Fees', (int) $q()->sum('provider_fee') + (int) $q()->sum('platform_fee'), 'money'),
            self::card('net', 'You receive', (int) $q()->sum('net_amount'), 'money'),
        ];
    }

    public function columns(): array
    {
        return [
            'paid_at' => ['Date', 'datetime'],
            'receipt' => ['Receipt', 'text'],
            'customer' => ['Customer', 'text'],
            'group' => ['Group', 'text'],
            'method' => ['Method', 'text'],
            'reference' => ['Provider reference', 'text'],
            'phone' => ['Phone', 'text'],
            'amount' => ['Amount', 'money'],
            'provider_fee' => ['Provider fee', 'money'],
            'platform_fee' => ['NguzoPay fee', 'money'],
            'net' => ['You receive', 'money'],
            'settled' => ['Settlement', 'text'],
            'status' => ['Status', 'text'],
        ];
    }

    /** @param  Payment  $p */
    public function row(Model $p): array
    {
        return [
            'paid_at' => $p->paid_at,
            'receipt' => $p->receipt_number,
            'customer' => $p->customer?->name ?? $p->payer_name,
            'group' => $p->customer?->group?->name,
            'method' => match (true) {
                $p->isManual() => (Payment::METHODS[$p->channel] ?? 'Manual').' (recorded)',
                $p->channel === 'bank' => self::PROVIDERS[$p->provider] ?? 'Bank',
                default => $p->operator?->label() ?? 'Mobile money',
            },
            'reference' => $p->provider_reference,
            'phone' => $p->payer_phone,
            'amount' => $p->amount,
            'provider_fee' => $p->provider_fee,
            'platform_fee' => $p->platform_fee,
            'net' => $p->net_amount,
            'settled' => $p->isManual() ? 'Not via NguzoPay' : ($p->settlement_id ? 'Included' : 'Next sweep'),
            'status' => $p->status === 'voided' ? 'Voided' : 'Received',
        ];
    }
}
