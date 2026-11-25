<?php

namespace App\Domain\Collections\Services;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Models\PaymentIntent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;

/**
 * Home dashboard numbers for one business: a series for the chart and the
 * stat cards, each compared with the previous period of the same length.
 * Aggregated in PHP so it behaves the same on SQLite and PostgreSQL.
 */
class CollectionsSummary
{
    public const METRICS = ['revenue', 'payments', 'success', 'customers'];

    public function build(Business $business, string $metric, string $period): array
    {
        $tz = config('nguzopay.settlement.timezone');
        $now = CarbonImmutable::now($tz);
        [$start, $step, $tick] = match ($period) {
            '30d' => [$now->subDays(29)->startOfDay(), CarbonInterval::day(), 'j M'],
            'year' => [$now->startOfYear(), CarbonInterval::week(), 'M'],
            default => [$now->subDays(6)->startOfDay(), CarbonInterval::hours(6), 'j M'],
        };
        $length = $start->diffInSeconds($now);
        $prevStart = $start->subSeconds($length);

        $payments = Payment::counted()->where('business_id', $business->id)
            ->where('paid_at', '>=', $prevStart->utc())->get(['amount', 'customer_id', 'paid_at']);
        $intents = PaymentIntent::where('business_id', $business->id)
            ->where('created_at', '>=', $prevStart->utc())->whereIn('status', ['succeeded', 'failed', 'expired'])
            ->get(['status', 'created_at']);

        $current = fn ($rows, $col) => $rows->filter(fn ($r) => $r->{$col}->gte($start));
        $previous = fn ($rows, $col) => $rows->filter(fn ($r) => $r->{$col}->lt($start));

        $value = function ($pays, $ints) use ($metric) {
            return match ($metric) {
                'payments' => $pays->count(),
                'customers' => $pays->pluck('customer_id')->filter()->unique()->count(),
                'success' => $ints->count() ? round($ints->where('status.value', 'succeeded')->count() / $ints->count() * 100, 1) : 0,
                default => (int) $pays->sum('amount'),
            };
        };

        $series = [];
        for ($from = $start; $from->lt($now); $from = $from->add($step)) {
            $to = $from->add($step);
            $in = fn ($rows, $col) => $rows->filter(fn ($r) => $r->{$col}->gte($from) && $r->{$col}->lt($to));
            $series[] = [
                'label' => $period === 'year' ? 'Week of '.$from->format('j M') : ($period === '30d' ? $from->format('j M') : $from->format('j M, H:i')),
                'tick' => $from->format($tick),
                'value' => $value($in($payments, 'paid_at'), $in($intents, 'created_at')),
            ];
        }

        $now_ = $value($current($payments, 'paid_at'), $current($intents, 'created_at'));
        $before = $value($previous($payments, 'paid_at'), $previous($intents, 'created_at'));
        $curPays = $current($payments, 'paid_at');
        $prevPays = $previous($payments, 'paid_at');

        $outstanding = (int) $business->invoices()->whereIn('status', ['open', 'partially_paid'])
            ->selectRaw('coalesce(sum(amount_due - amount_paid), 0) as v')->value('v');

        return [
            'metric' => $metric,
            'period' => $period,
            'headline' => $now_,
            'trend' => $this->trend($now_, $before),
            'series' => $series,
            'has_data' => $business->payments()->exists() || $business->invoices()->exists(),
            'stats' => [
                'collected' => (int) $curPays->sum('amount'),
                'collected_trend' => $this->trend((int) $curPays->sum('amount'), (int) $prevPays->sum('amount')),
                'payments' => $curPays->count(),
                'payments_trend' => $this->trend($curPays->count(), $prevPays->count()),
                'outstanding' => $outstanding,
                'unsettled' => (int) $business->payments()->settleable()->whereNull('settlement_id')->sum('net_amount'),
            ],
        ];
    }

    /** Percent change; null when there is nothing to compare with. */
    private function trend(float|int $now, float|int $before): ?float
    {
        if ($before == 0) {
            return null;
        }

        return round(($now - $before) / $before * 100, 1);
    }
}
