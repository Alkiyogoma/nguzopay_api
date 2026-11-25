<?php

namespace App\Domain\Collections\Reports\Admin;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Reports\PaymentsReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Every payment on the platform, for NGUZO finance and operations. */
class AdminPaymentsReport extends PaymentsReport
{
    use AcrossBusinesses;

    public function title(): string
    {
        return 'All payments';
    }

    protected function base(?Business $business): Builder
    {
        return Payment::query()->with('customer.group', 'business')->latest('paid_at');
    }

    protected function rules(): array
    {
        return [...parent::rules(), ...$this->businessRules()];
    }

    protected function filter(Builder $query, array $f): void
    {
        parent::filter($query, $f);
        $this->filterBusinesses($query, $f, 'payments.business_id');
    }

    protected function describe(array $f): array
    {
        return [...$this->describeBusinesses($f), ...parent::describe($f)];
    }

    public function summary(Builder $filtered, ?Business $business): array
    {
        $q = fn () => (clone $filtered)->reorder()->where('payments.status', 'succeeded');
        $platform = fn () => $q()->where('payments.provider', '!=', Payment::MANUAL);

        return [
            self::card('volume', 'Volume through NguzoPay', (int) $platform()->sum('amount'), 'money'),
            self::card('count', 'Payments', $q()->count()),
            self::card('revenue', 'NguzoPay fees', (int) $platform()->sum('platform_fee'), 'money'),
            self::card('provider_fees', 'Provider fees', (int) $platform()->sum('provider_fee'), 'money'),
        ];
    }

    public function columns(): array
    {
        $columns = parent::columns();
        $columns['net'] = ['Merchant receives', 'money'];

        return ['business' => ['Business', 'text'], 'provider' => ['Provider', 'text'], ...$columns];
    }

    /** @param  Payment  $p */
    public function row(Model $p): array
    {
        return ['business' => self::businessName($p->business), 'provider' => $p->provider, ...parent::row($p)];
    }
}
