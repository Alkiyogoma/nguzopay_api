<?php

namespace App\Domain\Collections\Reports;

use App\Domain\Business\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * One list page's data rules (D-020): its filters, its summary cards and its
 * export columns. The table, the cards, the Excel file and the printed PDF all
 * use the same definition, so they always agree.
 */
abstract class Report
{
    /** Hard cap on exported rows; the export says when it was cut. */
    public const EXPORT_LIMIT = 20_000;

    abstract public function title(): string;

    /** Query for this business, before filters. */
    abstract protected function base(Business $business): Builder;

    /** Column the date range filters on. */
    abstract protected function dateColumn(): string;

    /** Page-specific filters (search, status, …). */
    abstract protected function filter(Builder $query, array $f): void;

    /** @return list<array{key: string, label: string, value: int|float, format: string}> */
    abstract public function summary(Builder $filtered, Business $business): array;

    /** @return array<string, array{0: string, 1: string}> key => [label, format] */
    abstract public function columns(): array;

    /** @return array<string, mixed> raw values keyed like columns() */
    abstract public function row(Model $model): array;

    /** Extra validation rules for this report's filters. */
    protected function rules(): array
    {
        return [];
    }

    /** Human labels for the filters in use, for printed and exported headers. */
    protected function describe(array $f): array
    {
        return [];
    }

    public function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            ...$this->rules(),
        ]);
    }

    public function query(Business $business, array $f): Builder
    {
        $query = $this->base($business);
        $tz = config('nguzopay.settlement.timezone');
        $column = $this->dateColumn();

        // Dates are East Africa Time days, inclusive at both ends.
        if (! empty($f['from'])) {
            $from = CarbonImmutable::parse($f['from'], $tz)->startOfDay();
            $query->where($column, '>=', $this->isDateOnly() ? $from->toDateString() : $from->utc());
        }
        if (! empty($f['to'])) {
            $to = CarbonImmutable::parse($f['to'], $tz)->endOfDay();
            $query->where($column, '<=', $this->isDateOnly() ? $to->toDateString() : $to->utc());
        }

        $this->filter($query, $f);

        return $query;
    }

    /** True when the date column holds a date, not a timestamp. */
    protected function isDateOnly(): bool
    {
        return false;
    }

    public function filterLabels(array $f): array
    {
        $labels = $this->describe($f);
        if (! empty($f['from']) || ! empty($f['to'])) {
            $fmt = fn ($d) => CarbonImmutable::parse($d)->format('j M Y');
            $labels[] = 'Dates: '.(! empty($f['from']) ? $fmt($f['from']) : 'start').' – '.(! empty($f['to']) ? $fmt($f['to']) : 'today');
        }
        if (! empty($f['q'])) {
            $labels[] = 'Search: “'.$f['q'].'”';
        }

        return $labels;
    }

    /** A value as people read it (print view and Excel text cells). */
    public static function display(mixed $value, string $format): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($format) {
            'money' => 'TZS '.number_format((float) $value),
            'number' => number_format((float) $value),
            'percent' => rtrim(rtrim(number_format((float) $value, 1), '0'), '.').'%',
            'date' => CarbonImmutable::parse($value)->tz(config('nguzopay.settlement.timezone'))->format('j M Y'),
            'datetime' => CarbonImmutable::parse($value)->tz(config('nguzopay.settlement.timezone'))->format('j M Y, H:i'),
            default => (string) $value,
        };
    }

    protected static function card(string $key, string $label, int|float $value, string $format = 'number'): array
    {
        return compact('key', 'label', 'value', 'format');
    }

    protected static function like(string $q): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
    }
}
