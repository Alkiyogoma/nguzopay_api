<?php

namespace App\Domain\Collections\Reports;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceBatch;
use App\Domain\Business\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class InvoicesReport extends Report
{
    public function title(): string
    {
        return 'Invoices';
    }

    protected function base(?Business $business): Builder
    {
        return Invoice::where('invoices.business_id', $business->id)->with('customer.group', 'controlNumber')->latest();
    }

    protected function dateColumn(): string
    {
        return 'invoices.created_at';
    }

    protected function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['open', 'partially_paid', 'paid', 'void', 'unpaid', 'overdue'])],
            'customer_id' => ['nullable', 'string'],
            'group_id' => ['nullable', 'string'],
            'batch_id' => ['nullable', 'string'],
        ];
    }

    protected function filter(Builder $query, array $f): void
    {
        $status = $f['status'] ?? null;
        match ($status) {
            null => null,
            'unpaid' => $query->whereIn('status', ['open', 'partially_paid']),
            'overdue' => $query->whereIn('status', ['open', 'partially_paid'])->whereDate('due_date', '<', today()),
            default => $query->where('status', $status),
        };

        if (! empty($f['customer_id'])) {
            $query->where('customer_id', $f['customer_id']);
        }
        GroupFilter::apply($query, $f);
        if (! empty($f['batch_id'])) {
            $query->where('batch_id', $f['batch_id']);
        }

        if (! empty($f['q'])) {
            $like = self::like($f['q']);
            $digits = preg_replace('/\D/', '', $f['q']);
            $query->where(function ($w) use ($like, $digits) {
                $w->where('number', 'like', $like)->orWhere('external_reference', 'like', $like)->orWhere('description', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('reference', 'like', $like));
                if (strlen($digits) >= 4) {
                    $w->orWhereHas('controlNumber', fn ($c) => $c->where('number', 'like', "%{$digits}%"));
                }
            });
        }
    }

    protected function describe(array $f): array
    {
        $labels = ['unpaid' => 'Unpaid', 'overdue' => 'Overdue', 'open' => 'Open', 'partially_paid' => 'Part paid', 'paid' => 'Paid', 'void' => 'Void'];

        return array_values(array_filter([
            isset($f['status']) ? 'Status: '.$labels[$f['status']] : null,
            GroupFilter::label($f),
            ! empty($f['batch_id']) ? 'Batch: '.(InvoiceBatch::find($f['batch_id'])?->name ?? '—') : null,
        ]));
    }

    public function summary(Builder $filtered, ?Business $business): array
    {
        $live = (clone $filtered)->reorder()->where('status', '!=', 'void');
        $open = (clone $filtered)->reorder()->whereIn('status', ['open', 'partially_paid']);
        $balance = 'coalesce(sum(amount_due - amount_paid), 0) as v';

        return [
            self::card('invoiced', 'Invoiced', (int) (clone $live)->sum('amount_due'), 'money'),
            self::card('collected', 'Collected', (int) (clone $live)->sum('amount_paid'), 'money'),
            self::card('outstanding', 'Outstanding', (int) (clone $open)->selectRaw($balance)->value('v'), 'money'),
            self::card('overdue', 'Overdue', (int) (clone $open)->whereDate('due_date', '<', today())->selectRaw($balance)->value('v'), 'money'),
        ];
    }

    public function columns(): array
    {
        return [
            'number' => ['Invoice', 'text'],
            'customer' => ['Customer', 'text'],
            'customer_reference' => ['Customer ref.', 'text'],
            'group' => ['Group', 'text'],
            'control_number' => ['Control number', 'text'],
            'issued' => ['Issued', 'date'],
            'due' => ['Due', 'date'],
            'amount' => ['Amount', 'money'],
            'paid' => ['Paid', 'money'],
            'balance' => ['Balance', 'money'],
            'status' => ['Status', 'text'],
        ];
    }

    /** @param  Invoice  $i */
    public function row(Model $i): array
    {
        return [
            'number' => $i->number,
            'customer' => $i->customer?->name,
            'customer_reference' => $i->customer?->reference,
            'group' => $i->customer?->group?->name,
            'control_number' => $i->controlNumber?->number,
            'issued' => $i->created_at,
            'due' => $i->due_date,
            'amount' => $i->amount_due,
            'paid' => $i->amount_paid,
            'balance' => $i->balance(),
            'status' => $i->isOverdue() ? 'Overdue' : ['open' => 'Open', 'partially_paid' => 'Part paid', 'paid' => 'Paid', 'void' => 'Void'][$i->status->value],
        ];
    }
}
