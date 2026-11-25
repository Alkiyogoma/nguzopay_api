<?php

namespace App\Domain\Collections\Reports;

use App\Domain\Billing\Models\CustomerGroup;
use App\Domain\Business\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Totals per customer group (a school's classes): invoiced, paid, outstanding
 * and overdue. The date range picks which invoices count (by issue date).
 */
class GroupsReport extends Report
{
    private array $range = [];

    public function title(): string
    {
        return 'Groups';
    }

    protected function base(Business $business): Builder
    {
        $live = fn ($q) => $this->dated($q)->where('invoices.status', '!=', 'void');
        $open = fn ($q) => $this->dated($q)->whereIn('invoices.status', ['open', 'partially_paid']);
        $late = fn ($q) => $open($q)->whereDate('invoices.due_date', '<', today());

        return CustomerGroup::where('customer_groups.business_id', $business->id)
            ->withCount('customers')
            ->withSum(['invoices as invoiced' => $live], 'amount_due')
            ->withSum(['invoices as paid' => $live], 'amount_paid')
            ->withSum(['invoices as open_due' => $open], 'amount_due')
            ->withSum(['invoices as open_paid' => $open], 'amount_paid')
            ->withSum(['invoices as late_due' => $late], 'amount_due')
            ->withSum(['invoices as late_paid' => $late], 'amount_paid')
            ->orderBy('name');
    }

    /** The date range filters the invoices inside each total, not the groups. */
    public function query(Business $business, array $f): Builder
    {
        $this->range = array_intersect_key($f, array_flip(['from', 'to']));
        $query = $this->base($business);
        $this->filter($query, $f);

        return $query;
    }

    protected function dateColumn(): string
    {
        return 'invoices.created_at';
    }

    private function dated($q)
    {
        $tz = config('nguzopay.settlement.timezone');
        if (! empty($this->range['from'])) {
            $q->where('invoices.created_at', '>=', CarbonImmutable::parse($this->range['from'], $tz)->startOfDay()->utc());
        }
        if (! empty($this->range['to'])) {
            $q->where('invoices.created_at', '<=', CarbonImmutable::parse($this->range['to'], $tz)->endOfDay()->utc());
        }

        return $q;
    }

    protected function filter(Builder $query, array $f): void
    {
        if (! empty($f['q'])) {
            $query->where('name', 'like', self::like($f['q']));
        }
    }

    public function summary(Builder $filtered, Business $business): array
    {
        $groups = (clone $filtered)->get();

        return [
            self::card('customers', 'Customers in groups', (int) $groups->sum('customers_count')),
            self::card('paid', 'Paid', (int) $groups->sum('paid'), 'money'),
            self::card('outstanding', 'Outstanding', (int) $groups->sum(fn ($g) => $g->open_due - $g->open_paid), 'money'),
            self::card('overdue', 'Overdue', (int) $groups->sum(fn ($g) => $g->late_due - $g->late_paid), 'money'),
        ];
    }

    public function columns(): array
    {
        return [
            'name' => ['Group', 'text'],
            'customers' => ['Customers', 'number'],
            'invoiced' => ['Invoiced', 'money'],
            'paid' => ['Paid', 'money'],
            'outstanding' => ['Outstanding', 'money'],
            'overdue' => ['Overdue', 'money'],
            'rate' => ['Collected', 'percent'],
        ];
    }

    /** @param  CustomerGroup  $g */
    public function row(Model $g): array
    {
        $invoiced = (int) $g->invoiced;

        return [
            'name' => $g->name,
            'customers' => (int) $g->customers_count,
            'invoiced' => $invoiced,
            'paid' => (int) $g->paid,
            'outstanding' => (int) $g->open_due - (int) $g->open_paid,
            'overdue' => (int) $g->late_due - (int) $g->late_paid,
            'rate' => $invoiced ? round($g->paid / $invoiced * 100, 1) : 0,
        ];
    }
}
