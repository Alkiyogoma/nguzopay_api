<?php

namespace App\Domain\Collections\Reports\Admin;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Business\Enums\BusinessStatus;
use App\Domain\Business\Enums\BusinessType;
use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Reports\Report;
use App\Domain\Platform\Analytics\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Every business with how it performs. The date range is the performance
 * window (money collected, payments, fees); it does not hide businesses.
 */
class BusinessesReport extends Report
{
    private ?Period $window = null;

    private const SORTS = ['collected', 'payments', 'revenue', 'outstanding', 'customers', 'newest', 'name'];

    public function title(): string
    {
        return 'Businesses';
    }

    protected function base(?Business $business): Builder
    {
        return Business::query()->with('owner.user');
    }

    protected function dateColumn(): string
    {
        return 'businesses.created_at';
    }

    protected function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(BusinessStatus::class)],
            'sector' => ['nullable', Rule::enum(BusinessType::class)],
            'sort' => ['nullable', Rule::in(self::SORTS)],
        ];
    }

    /** Not date-filtered: the range only bounds the performance figures. */
    public function query(?Business $business, array $f): Builder
    {
        $period = $this->window = $this->period($f);
        $paid = fn ($q) => $period->apply($q->where('payments.status', 'succeeded')->where('payments.provider', '!=', Payment::MANUAL), 'payments.paid_at');

        $query = $this->base(null)
            ->withCount(['customers', 'payments as payments_count' => $paid])
            ->withSum(['payments as collected' => $paid], 'amount')
            ->withSum(['payments as revenue' => $paid], 'platform_fee')
            ->withMax(['payments as last_payment_at' => fn ($q) => $q->where('payments.status', 'succeeded')], 'paid_at')
            ->addSelect(['outstanding' => Invoice::query()->selectRaw('coalesce(sum(amount_due - amount_paid), 0)')
                ->whereColumn('invoices.business_id', 'businesses.id')
                ->whereIn('status', [InvoiceStatus::Open->value, InvoiceStatus::PartiallyPaid->value])]);

        $this->filter($query, $f);

        return match ($f['sort'] ?? 'collected') {
            'payments' => $query->orderByDesc('payments_count'),
            'revenue' => $query->orderByDesc('revenue'),
            'outstanding' => $query->orderByDesc('outstanding'),
            'customers' => $query->orderByDesc('customers_count'),
            'newest' => $query->latest(),
            'name' => $query->orderByRaw('coalesce(trading_name, legal_name)'),
            default => $query->orderByDesc('collected')->latest(),
        };
    }

    public function period(array $f): Period
    {
        return empty($f['from']) && empty($f['to']) ? Period::allTime() : Period::fromInput($f['from'] ?? '2000-01-01', $f['to'] ?? null);
    }

    protected function filter(Builder $query, array $f): void
    {
        if (! empty($f['status'])) {
            $query->where('businesses.status', $f['status']);
        }
        if (! empty($f['sector'])) {
            $query->where('businesses.type', $f['sector']);
        }
        if (! empty($f['q'])) {
            $like = self::like($f['q']);
            $query->where(fn ($w) => $w->where('legal_name', 'like', $like)->orWhere('trading_name', 'like', $like)
                ->orWhere('code', $f['q'])->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)
                ->orWhere('tin', preg_replace('/\D/', '', $f['q']) ?: '-'));
        }
    }

    protected function describe(array $f): array
    {
        return array_values(array_filter([
            ! empty($f['status']) ? 'Status: '.ucfirst(str_replace('_', ' ', $f['status'])) : null,
            ! empty($f['sector']) ? 'Sector: '.BusinessType::from($f['sector'])->label() : null,
        ]));
    }

    public function summary(Builder $filtered, ?Business $business): array
    {
        $ids = (clone $filtered)->reorder()->setEagerLoads([])->select('businesses.id');

        return [
            self::card('businesses', 'Businesses', (clone $filtered)->reorder()->count()),
            self::card('active', 'Active', (clone $filtered)->reorder()->where('businesses.status', BusinessStatus::Active)->count()),
            self::card('collected', 'Collected', (int) $this->paymentsIn($ids)->sum('amount'), 'money'),
            self::card('revenue', 'NguzoPay fees', (int) $this->paymentsIn($ids)->sum('platform_fee'), 'money'),
        ];
    }

    /** Payments of the filtered businesses inside the performance window set by query(). */
    private function paymentsIn(Builder $ids): Builder
    {
        return ($this->window ?? Period::allTime())->apply(Payment::settleable()->whereIn('business_id', $ids->toBase()), 'paid_at');
    }

    public function columns(): array
    {
        return [
            'code' => ['Code', 'text'],
            'name' => ['Business', 'text'],
            'sector' => ['Sector', 'text'],
            'status' => ['Status', 'text'],
            'owner' => ['Owner', 'text'],
            'joined' => ['Joined', 'date'],
            'customers' => ['Customers', 'number'],
            'payments' => ['Payments', 'number'],
            'collected' => ['Collected', 'money'],
            'revenue' => ['NguzoPay fees', 'money'],
            'outstanding' => ['Outstanding', 'money'],
            'last_payment' => ['Last payment', 'datetime'],
        ];
    }

    /** @param  Business  $b */
    public function row(Model $b): array
    {
        return [
            'code' => $b->code,
            'name' => $b->displayName(),
            'sector' => $b->type?->label(),
            'status' => ucfirst(str_replace('_', ' ', $b->status->value)),
            'owner' => $b->owner?->user?->name,
            'joined' => $b->created_at,
            'customers' => (int) $b->customers_count,
            'payments' => (int) $b->payments_count,
            'collected' => (int) $b->collected,
            'revenue' => (int) $b->revenue,
            'outstanding' => (int) $b->outstanding,
            'last_payment' => $b->last_payment_at,
        ];
    }
}
