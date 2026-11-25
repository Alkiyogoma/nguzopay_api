<?php

namespace App\Domain\Collections\Reports;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\Settlement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class SettlementsReport extends Report
{
    private const STATUS = ['pending_payout' => 'To be paid out', 'paid' => 'Paid', 'failed' => 'Failed', 'covered' => 'Already withdrawn'];

    public function title(): string
    {
        return 'Settlements';
    }

    protected function base(?Business $business): Builder
    {
        return Settlement::where('business_id', $business->id)->latest('settlement_date');
    }

    protected function dateColumn(): string
    {
        return 'settlement_date';
    }

    protected function isDateOnly(): bool
    {
        return true;
    }

    protected function rules(): array
    {
        return ['status' => ['nullable', Rule::in(array_keys(self::STATUS))]];
    }

    protected function filter(Builder $query, array $f): void
    {
        if (! empty($f['status'])) {
            $query->where('status', $f['status']);
        }
        if (! empty($f['q'])) {
            $query->where('payout_reference', 'like', self::like($f['q']));
        }
    }

    protected function describe(array $f): array
    {
        return isset($f['status']) ? ['Status: '.self::STATUS[$f['status']]] : [];
    }

    public function summary(Builder $filtered, ?Business $business): array
    {
        $q = fn () => (clone $filtered)->reorder();

        return [
            self::card('collected', 'Collected', (int) $q()->sum('gross_amount'), 'money'),
            self::card('fees', 'Fees', (int) $q()->sum('provider_fees') + (int) $q()->sum('platform_fees'), 'money'),
            self::card('net', 'Settled to you', (int) $q()->sum('net_amount'), 'money'),
            self::card('pending', 'To be paid out', (int) $q()->where('status', 'pending_payout')->sum('net_amount'), 'money'),
        ];
    }

    public function columns(): array
    {
        return [
            'date' => ['Date', 'date'],
            'payments' => ['Payments', 'number'],
            'collected' => ['Collected', 'money'],
            'provider_fees' => ['Provider fees', 'money'],
            'platform_fees' => ['NguzoPay fees', 'money'],
            'net' => ['Paid to you', 'money'],
            'status' => ['Status', 'text'],
            'payout_reference' => ['Payout reference', 'text'],
        ];
    }

    /** @param  Settlement  $s */
    public function row(Model $s): array
    {
        return [
            'date' => $s->settlement_date,
            'payments' => $s->payments_count,
            'collected' => $s->gross_amount,
            'provider_fees' => $s->provider_fees,
            'platform_fees' => $s->platform_fees,
            'net' => $s->net_amount,
            'status' => self::STATUS[$s->status] ?? $s->status,
            'payout_reference' => $s->payout_reference,
        ];
    }
}
