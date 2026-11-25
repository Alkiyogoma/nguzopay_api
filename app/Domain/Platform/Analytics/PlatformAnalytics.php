<?php

namespace App\Domain\Platform\Analytics;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Customer;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Business\Enums\BusinessStatus;
use App\Domain\Business\Enums\BusinessType;
use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Models\PaymentIntent;
use App\Domain\Collections\Models\Settlement;
use App\Domain\Identity\Models\User;
use App\Domain\Payouts\Models\Payout;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Back-office numbers. "Volume" is money that moved through NguzoPay (manual
 * entries excluded); "revenue" is NguzoPay's own fee. Every figure can be
 * narrowed to one business, which is how a business's performance tab works.
 */
class PlatformAnalytics
{
    private const PROVIDERS = ['mock' => 'Test network', 'snippe' => 'Snippe', 'azampay' => 'Azampay', 'selcom' => 'Selcom', 'crdb' => 'CRDB Bank', 'nmb' => 'NMB Bank'];

    /** The Analytics page. */
    public function platform(Period $period): array
    {
        $previous = $period->previous();
        $now = $this->totals($period);
        $before = $previous ? $this->totals($previous) : null;

        $businesses = Business::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'period' => $period->toArray(),
            'cards' => $this->cards($now, $before, [
                ['new_businesses', 'New businesses', 'number'],
                ['new_users', 'New users', 'number'],
            ]),
            'series' => $this->series($period),
            'providers' => $this->byProvider($period),
            'sectors' => $this->bySector($period),
            'top_businesses' => $this->topBusinesses($period),
            'businesses_by_status' => collect(BusinessStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($businesses[$s->value] ?? 0)]),
        ];
    }

    /** One business's performance tab. */
    public function business(Business $business, Period $period): array
    {
        $previous = $period->previous();
        $now = $this->totals($period, $business->id);
        $before = $previous ? $this->totals($previous, $business->id) : null;

        $open = Invoice::where('business_id', $business->id)->whereIn('status', [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid]);
        $overdue = (clone $open)->whereNotNull('due_date')->where('due_date', '<', now(Period::tz())->toDateString());
        $invoiced = $period->apply(Invoice::where('business_id', $business->id)->where('status', '!=', InvoiceStatus::Void), 'invoices.created_at');
        $invoicedTotal = (int) (clone $invoiced)->sum('amount_due');
        $invoicedPaid = (int) (clone $invoiced)->sum('amount_paid');

        return [
            'period' => $period->toArray(),
            'cards' => $this->cards($now, $before, [['new_customers', 'New customers', 'number']]),
            'series' => $this->series($period, $business->id),
            'providers' => $this->byProvider($period, $business->id),
            'position' => [
                'customers' => Customer::where('business_id', $business->id)->count(),
                'outstanding' => (int) (clone $open)->sum(DB::raw('amount_due - amount_paid')),
                'overdue' => (int) (clone $overdue)->sum(DB::raw('amount_due - amount_paid')),
                'overdue_invoices' => (clone $overdue)->count(),
                'invoiced' => $invoicedTotal,
                'collection_rate' => $invoicedTotal ? round($invoicedPaid / $invoicedTotal * 100, 1) : null,
                'credit_held' => (int) Customer::where('business_id', $business->id)->sum('credit_balance'),
                'pending_payout' => (int) Settlement::where('business_id', $business->id)->where('status', 'pending_payout')->sum('net_amount'),
                'unsettled' => (int) Payment::settleable()->where('business_id', $business->id)->whereNull('settlement_id')->sum('net_amount'),
                'last_payment_at' => Payment::counted()->where('business_id', $business->id)->max('paid_at'),
                'team' => $business->members()->count(),
                'all_time_volume' => (int) Payment::settleable()->where('business_id', $business->id)->sum('amount'),
            ],
        ];
    }

    /**
     * The Overview strip (merchant Home and admin): money in, money out, outcomes
     * of payment requests, and two charts. One business or the whole platform.
     */
    public function overview(Period $period, ?string $businessId = null): array
    {
        $paid = fn () => $this->scope($period->apply(Payment::settleable(), 'paid_at'), $businessId);
        $payouts = fn () => $this->scope($period->apply(Payout::where('status', 'completed'), 'processed_at'), $businessId);
        $requests = fn () => $this->scope($period->apply(PaymentIntent::query(), 'created_at'), $businessId);
        $waiting = fn () => $this->scope(PaymentIntent::whereIn('status', ['pending', 'processing']), $businessId);

        $ok = $requests()->where('status', 'succeeded')->count();
        $failed = $requests()->whereIn('status', ['failed', 'expired'])->count();

        $interval = $period->interval();
        $bucket = fn (string $col) => Period::bucketSql($col, $interval);
        $group = fn ($q, string $col, string $select) => $q->toBase()->selectRaw("{$bucket($col)} as bucket, {$select}")->groupBy('bucket')->get()->keyBy('bucket');

        $payments = $group($paid(), 'paid_at', 'count(*) as n, sum(amount) as amount');
        $out = $group($payouts(), 'processed_at', 'sum(amount) as amount');
        $states = $group($requests(), 'created_at', "sum(case when status in ('failed','expired') then 1 else 0 end) as failed, sum(case when status in ('pending','processing') then 1 else 0 end) as pending");

        $first = null;
        if (! $period->from) {
            $min = $this->scope(Payment::settleable(), $businessId)->min('paid_at');
            $first = $min ? CarbonImmutable::parse($min) : null;
        }

        return [
            'period' => $period->toArray(),
            'cards' => [
                'payments' => ['amount' => (int) $paid()->sum('amount'), 'count' => $paid()->count()],
                'payouts' => ['amount' => (int) $payouts()->sum('amount'), 'count' => $payouts()->count()],
                'successful' => ['count' => $paid()->count(), 'amount' => (int) $paid()->sum('amount')],
                'pending' => ['count' => $waiting()->count(), 'amount' => (int) $waiting()->sum('amount')],
                'success_rate' => ['rate' => ($ok + $failed) ? round($ok / ($ok + $failed) * 100, 1) : null, 'failed' => $failed],
            ],
            'series' => array_map(fn ($k) => [
                'key' => $k,
                'completed' => (int) ($payments[$k]->n ?? 0),
                'failed' => (int) ($states[$k]->failed ?? 0),
                'pending' => (int) ($states[$k]->pending ?? 0),
                'payments' => (int) ($payments[$k]->amount ?? 0),
                'payouts' => (int) ($out[$k]->amount ?? 0),
            ], $period->buckets($first)),
        ];
    }

    /** @return array<string, int|float|null> */
    public function totals(Period $period, ?string $businessId = null): array
    {
        $paid = fn () => $this->scope($period->apply(Payment::settleable(), 'paid_at'), $businessId);
        $requests = fn () => $this->scope($period->apply(PaymentIntent::query(), 'created_at'), $businessId);
        $finished = $requests()->whereIn('status', ['succeeded', 'failed', 'expired'])->count();
        $volume = (int) $paid()->sum('amount');
        $count = $paid()->count();

        return [
            'volume' => $volume,
            'payments' => $count,
            'revenue' => (int) $paid()->sum('platform_fee'),
            'provider_fees' => (int) $paid()->sum('provider_fee'),
            'average' => $count ? (int) round($volume / $count) : 0,
            'manual' => (int) $this->scope($period->apply(Payment::counted()->where('provider', Payment::MANUAL), 'paid_at'), $businessId)->sum('amount'),
            'success_rate' => $finished ? round($requests()->where('status', 'succeeded')->count() / $finished * 100, 1) : null,
            'paying_customers' => $paid()->distinct()->count('customer_id'),
            'active_businesses' => $businessId ? null : $paid()->distinct()->count('business_id'),
            'new_businesses' => $businessId ? null : $period->apply(Business::query(), 'created_at')->count(),
            'new_users' => $businessId ? null : $period->apply(User::query(), 'created_at')->count(),
            'new_customers' => $this->scope($period->apply(Customer::query(), 'created_at'), $businessId)->count(),
        ];
    }

    /** Cards with the change against the previous period. */
    private function cards(array $now, ?array $before, array $extra): array
    {
        $defs = [
            ['volume', 'Volume', 'money'],
            ['revenue', 'NguzoPay fees', 'money'],
            ['payments', 'Payments', 'number'],
            ['average', 'Average payment', 'money'],
            ['success_rate', 'Prompt success rate', 'percent'],
            ['paying_customers', 'Paying customers', 'number'],
            ...($now['active_businesses'] !== null ? [['active_businesses', 'Businesses collecting', 'number']] : []),
            ['manual', 'Recorded by hand', 'money'],
            ...$extra,
        ];

        return array_map(fn ($d) => [
            'key' => $d[0],
            'label' => $d[1],
            'format' => $d[2],
            'value' => $now[$d[0]] ?? 0,
            'previous' => $before[$d[0]] ?? null,
            'change' => $before ? Period::change($now[$d[0]] ?? 0, $before[$d[0]] ?? null) : null,
        ], $defs);
    }

    /** @return list<array{key: string, volume: int, revenue: int, payments: int}> */
    public function series(Period $period, ?string $businessId = null): array
    {
        $interval = $period->interval();
        $bucket = Period::bucketSql('paid_at', $interval);

        $rows = $this->scope($period->apply(Payment::settleable(), 'paid_at'), $businessId)
            ->toBase()
            ->selectRaw("{$bucket} as bucket, sum(amount) as volume, sum(platform_fee) as revenue, count(*) as payments")
            ->groupBy('bucket')->get()->keyBy('bucket');

        $first = null;
        if (! $period->from) {
            $min = $this->scope(Payment::settleable(), $businessId)->min('paid_at');
            $first = $min ? CarbonImmutable::parse($min) : null;
        }

        return array_map(fn ($key) => [
            'key' => $key,
            'volume' => (int) ($rows[$key]->volume ?? 0),
            'revenue' => (int) ($rows[$key]->revenue ?? 0),
            'payments' => (int) ($rows[$key]->payments ?? 0),
        ], $period->buckets($first));
    }

    public function byProvider(Period $period, ?string $businessId = null): array
    {
        $rows = $this->scope($period->apply(Payment::settleable(), 'paid_at'), $businessId)->toBase()
            ->selectRaw('provider, sum(amount) as volume, count(*) as payments, sum(provider_fee) as fees')
            ->groupBy('provider')->orderByDesc('volume')->get();
        $total = (int) $rows->sum('volume');

        // Prompt success per mobile-money provider (bank billers have no prompts).
        $prompts = $this->scope($period->apply(PaymentIntent::query(), 'payment_intents.created_at'), $businessId, 'payment_intents.business_id')->toBase()
            ->join('payment_attempts', 'payment_attempts.payment_intent_id', '=', 'payment_intents.id')
            ->whereIn('payment_attempts.status', ['succeeded', 'failed', 'expired'])
            ->selectRaw("payment_attempts.provider as provider, count(*) as finished, sum(case when payment_attempts.status = 'succeeded' then 1 else 0 end) as ok")
            ->groupBy('payment_attempts.provider')->get()->keyBy('provider');

        return $rows->map(fn ($r) => [
            'provider' => $r->provider,
            'label' => self::PROVIDERS[$r->provider] ?? ucfirst($r->provider),
            'channel' => in_array($r->provider, ['crdb', 'nmb'], true) ? 'bank' : 'mobile_money',
            'volume' => (int) $r->volume,
            'payments' => (int) $r->payments,
            'fees' => (int) $r->fees,
            'share' => $total ? round($r->volume / $total * 100, 1) : 0,
            'success_rate' => isset($prompts[$r->provider]) && $prompts[$r->provider]->finished
                ? round($prompts[$r->provider]->ok / $prompts[$r->provider]->finished * 100, 1) : null,
        ])->values()->all();
    }

    public function bySector(Period $period): array
    {
        $rows = $period->apply(Payment::settleable(), 'paid_at')->toBase()
            ->join('businesses', 'businesses.id', '=', 'payments.business_id')
            ->selectRaw('businesses.type as sector, sum(payments.amount) as volume, count(*) as payments, count(distinct payments.business_id) as businesses')
            ->groupBy('businesses.type')->orderByDesc('volume')->get();
        $total = (int) $rows->sum('volume');

        return $rows->map(fn ($r) => [
            'sector' => $r->sector,
            'label' => BusinessType::tryFrom($r->sector)?->label() ?? $r->sector,
            'volume' => (int) $r->volume,
            'payments' => (int) $r->payments,
            'businesses' => (int) $r->businesses,
            'share' => $total ? round($r->volume / $total * 100, 1) : 0,
        ])->values()->all();
    }

    public function topBusinesses(Period $period, int $limit = 10): array
    {
        $rows = $period->apply(Payment::settleable(), 'paid_at')->toBase()
            ->selectRaw('business_id, sum(amount) as volume, count(*) as payments, sum(platform_fee) as revenue')
            ->groupBy('business_id')->orderByDesc('volume')->limit($limit)->get();
        $names = Business::withTrashed()->whereIn('id', $rows->pluck('business_id'))->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'id' => $r->business_id,
            'name' => $names[$r->business_id]?->displayName(),
            'code' => $names[$r->business_id]?->code,
            'sector' => $names[$r->business_id]?->type?->label(),
            'volume' => (int) $r->volume,
            'payments' => (int) $r->payments,
            'revenue' => (int) $r->revenue,
        ])->values()->all();
    }

    private function scope(Builder $query, ?string $businessId, ?string $column = null): Builder
    {
        return $businessId ? $query->where($column ?? $query->getModel()->getTable().'.business_id', $businessId) : $query;
    }
}
