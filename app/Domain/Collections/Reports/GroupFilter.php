<?php

namespace App\Domain\Collections\Reports;

use App\Domain\Billing\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Builder;

/** Shared "group_id" filter for invoices and payments (through their customer). "none" = no group. */
final class GroupFilter
{
    public static function apply(Builder $query, array $f): void
    {
        if (empty($f['group_id'])) {
            return;
        }

        $query->whereHas('customer', fn ($c) => $f['group_id'] === 'none' ? $c->whereNull('group_id') : $c->where('group_id', $f['group_id']));
    }

    public static function label(array $f): ?string
    {
        if (empty($f['group_id'])) {
            return null;
        }

        return 'Group: '.($f['group_id'] === 'none' ? 'No group' : (CustomerGroup::find($f['group_id'])?->name ?? 'Unknown'));
    }
}
