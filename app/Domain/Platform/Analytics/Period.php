<?php

namespace App\Domain\Platform\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * A reporting window in East Africa Time days, inclusive at both ends, with the
 * equal-length window before it (for "vs previous period" trends).
 */
final class Period
{
    private function __construct(
        public readonly ?CarbonImmutable $from,   // UTC instant, null = all time
        public readonly CarbonImmutable $to,      // UTC instant
    ) {}

    public static function tz(): string
    {
        return (string) config('nguzopay.settlement.timezone');
    }

    /** From optional Y-m-d strings; defaults to the last 30 days. */
    public static function fromInput(?string $from, ?string $to, int $defaultDays = 30): self
    {
        $tz = self::tz();
        $end = ($to ? CarbonImmutable::parse($to, $tz) : CarbonImmutable::now($tz))->endOfDay();
        $start = $from ? CarbonImmutable::parse($from, $tz)->startOfDay() : $end->subDays($defaultDays - 1)->startOfDay();

        return new self($start->utc(), $end->utc());
    }

    /** Today | 7d | 30d | 90d | all, or an explicit from/to. */
    public static function fromPreset(?string $preset, ?string $from = null, ?string $to = null): self
    {
        if ($from || $to) {
            return self::fromInput($from, $to);
        }

        return match ($preset) {
            'today' => self::fromInput(null, null, 1),
            '30d' => self::fromInput(null, null, 30),
            '90d' => self::fromInput(null, null, 90),
            'all' => self::allTime(),
            default => self::fromInput(null, null, 7),
        };
    }

    public static function allTime(): self
    {
        return new self(null, CarbonImmutable::now()->utc());
    }

    public function previous(): ?self
    {
        if (! $this->from) {
            return null;
        }
        $length = $this->from->diffInSeconds($this->to) + 1;

        return new self($this->from->subSeconds($length), $this->from->subSecond());
    }

    public function days(): int
    {
        return $this->from ? (int) ceil(($this->from->diffInSeconds($this->to) + 1) / 86400) : 0;
    }

    /** Daily buckets up to ~3 months, monthly beyond. */
    public function interval(): string
    {
        return $this->from && $this->days() <= 93 ? 'day' : 'month';
    }

    public function apply(Builder|QueryBuilder $query, string $column): Builder|QueryBuilder
    {
        if ($this->from) {
            $query->where($column, '>=', $this->from);
        }

        return $query->where($column, '<=', $this->to);
    }

    /** SQL for a column's EAT day ("Y-m-d") or month ("Y-m"). EAT is UTC+3 all year (no DST). */
    public static function bucketSql(string $column, string $interval): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'pgsql' => "to_char({$column} + interval '3 hours', '".($interval === 'day' ? 'YYYY-MM-DD' : 'YYYY-MM')."')",
            'mysql', 'mariadb' => "date_format(date_add({$column}, interval 3 hour), '".($interval === 'day' ? '%Y-%m-%d' : '%Y-%m')."')",
            default => "strftime('".($interval === 'day' ? '%Y-%m-%d' : '%Y-%m')."', {$column}, '+3 hours')",
        };
    }

    /** Every bucket key in the window, so gaps show as zero. @return list<string> */
    public function buckets(?CarbonImmutable $firstData = null): array
    {
        $tz = self::tz();
        $interval = $this->interval();
        $cursor = ($this->from ?? $firstData ?? CarbonImmutable::now())->tz($tz);
        $end = $this->to->tz($tz);
        $cursor = $interval === 'day' ? $cursor->startOfDay() : $cursor->startOfMonth();

        $keys = [];
        while ($cursor <= $end && count($keys) < 400) {
            $keys[] = $cursor->format($interval === 'day' ? 'Y-m-d' : 'Y-m');
            $cursor = $interval === 'day' ? $cursor->addDay() : $cursor->addMonth();
        }

        return $keys;
    }

    public function toArray(): array
    {
        $tz = self::tz();

        return [
            'from' => $this->from?->tz($tz)->toDateString(),
            'to' => $this->to->tz($tz)->toDateString(),
            'interval' => $this->interval(),
        ];
    }

    /** Percent change, null when there is no base to compare with. */
    public static function change(int|float $now, int|float|null $before): ?float
    {
        if ($before === null || $before == 0) {
            return null;
        }

        return round(($now - $before) / abs($before) * 100, 1);
    }
}
