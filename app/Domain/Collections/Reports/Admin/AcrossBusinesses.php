<?php

namespace App\Domain\Collections\Reports\Admin;

use App\Domain\Business\Enums\BusinessType;
use App\Domain\Business\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/** Back-office versions of the merchant reports: every business, filterable by one business or sector. */
trait AcrossBusinesses
{
    protected function businessRules(): array
    {
        return [
            'business_id' => ['nullable', 'string'],
            'sector' => ['nullable', Rule::enum(BusinessType::class)],
        ];
    }

    protected function filterBusinesses(Builder $query, array $f, string $column): void
    {
        if (! empty($f['business_id'])) {
            $query->where($column, $f['business_id']);
        }
        if (! empty($f['sector'])) {
            $query->whereIn($column, Business::withTrashed()->where('type', $f['sector'])->select('id'));
        }
    }

    protected function describeBusinesses(array $f): array
    {
        return array_values(array_filter([
            ! empty($f['business_id']) ? 'Business: '.(Business::withTrashed()->find($f['business_id'])?->displayName() ?? '—') : null,
            ! empty($f['sector']) ? 'Sector: '.BusinessType::from($f['sector'])->label() : null,
        ]));
    }

    protected static function businessName(?Business $b): ?string
    {
        return $b ? $b->displayName().' ('.$b->code.')' : null;
    }
}
