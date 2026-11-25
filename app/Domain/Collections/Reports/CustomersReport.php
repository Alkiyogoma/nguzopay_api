<?php

namespace App\Domain\Collections\Reports;

use App\Domain\Billing\Models\Customer;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Business\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class CustomersReport extends Report
{
    private const OPEN = ['open', 'partially_paid'];

    public function title(): string
    {
        return 'Customers';
    }

    protected function base(Business $business): Builder
    {
        return Customer::where('customers.business_id', $business->id)
            ->with('accountNumber', 'group')
            ->withSum(['invoices as due_sum' => fn ($i) => $i->whereIn('status', self::OPEN)], 'amount_due')
            ->withSum(['invoices as paid_sum' => fn ($i) => $i->whereIn('status', self::OPEN)], 'amount_paid')
            ->withCount(['invoices as open_invoices_count' => fn ($i) => $i->whereIn('status', self::OPEN)])
            ->orderBy('name');
    }

    protected function dateColumn(): string
    {
        return 'customers.created_at';
    }

    protected function rules(): array
    {
        return ['balance' => ['nullable', Rule::in(['owing', 'credit', 'settled'])], 'group_id' => ['nullable', 'string']];
    }

    protected function filter(Builder $query, array $f): void
    {
        if (! empty($f['q'])) {
            $like = self::like($f['q']);
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('reference', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like));
        }

        if (! empty($f['group_id'])) {
            $f['group_id'] === 'none' ? $query->whereNull('group_id') : $query->where('group_id', $f['group_id']);
        }

        $owing = fn ($q) => $q->whereIn('status', self::OPEN);
        match ($f['balance'] ?? null) {
            'owing' => $query->whereHas('invoices', $owing),
            'credit' => $query->where('credit_balance', '>', 0),
            'settled' => $query->whereDoesntHave('invoices', $owing)->where('credit_balance', 0),
            default => null,
        };
    }

    protected function describe(array $f): array
    {
        return array_values(array_filter([
            isset($f['balance']) ? 'Balance: '.['owing' => 'Owing', 'credit' => 'Has credit', 'settled' => 'Settled'][$f['balance']] : null,
            GroupFilter::label($f),
        ]));
    }

    public function summary(Builder $filtered, Business $business): array
    {
        $ids = (clone $filtered)->reorder()->select('customers.id');
        $owed = (int) Invoice::whereIn('customer_id', $ids)->whereIn('status', self::OPEN)->selectRaw('coalesce(sum(amount_due - amount_paid), 0) as v')->value('v');
        $owing = (int) Invoice::whereIn('customer_id', (clone $filtered)->reorder()->select('customers.id'))->whereIn('status', self::OPEN)->distinct()->count('customer_id');

        return [
            self::card('customers', 'Customers', (clone $filtered)->reorder()->count()),
            self::card('owing', 'Customers owing', $owing),
            self::card('owed', 'Total owed', $owed, 'money'),
            self::card('credit', 'Credit held', (int) (clone $filtered)->reorder()->sum('credit_balance'), 'money'),
        ];
    }

    public function columns(): array
    {
        return [
            'name' => ['Name', 'text'],
            'reference' => ['Reference', 'text'],
            'group' => ['Group', 'text'],
            'phone' => ['Phone', 'text'],
            'email' => ['Email', 'text'],
            'account_number' => ['Account number', 'text'],
            'open_invoices' => ['Open invoices', 'number'],
            'owes' => ['Owes', 'money'],
            'credit' => ['Credit', 'money'],
            'created_at' => ['Added', 'date'],
        ];
    }

    /** @param  Customer  $c */
    public function row(Model $c): array
    {
        return [
            'name' => $c->name,
            'reference' => $c->reference,
            'group' => $c->group?->name,
            'phone' => $c->phone,
            'email' => $c->email,
            'account_number' => $c->accountNumber?->number,
            'open_invoices' => (int) $c->open_invoices_count,
            'owes' => (int) $c->due_sum - (int) $c->paid_sum,
            'credit' => $c->credit_balance,
            'created_at' => $c->created_at,
        ];
    }
}
