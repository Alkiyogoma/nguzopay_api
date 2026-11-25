<?php

namespace App\Domain\Collections\Reports\Admin;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\Settlement;
use App\Domain\Collections\Reports\SettlementsReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Every business's T+0 settlements: what finance must pay out. */
class AdminSettlementsReport extends SettlementsReport
{
    use AcrossBusinesses;

    public function title(): string
    {
        return 'All settlements';
    }

    protected function base(?Business $business): Builder
    {
        return Settlement::query()->with('business')->latest('settlement_date');
    }

    protected function rules(): array
    {
        return [...parent::rules(), ...$this->businessRules()];
    }

    protected function filter(Builder $query, array $f): void
    {
        parent::filter($query, $f);
        $this->filterBusinesses($query, $f, 'settlements.business_id');
    }

    protected function describe(array $f): array
    {
        return [...$this->describeBusinesses($f), ...parent::describe($f)];
    }

    public function summary(Builder $filtered, ?Business $business): array
    {
        $q = fn () => (clone $filtered)->reorder();

        return [
            self::card('collected', 'Collected', (int) $q()->sum('gross_amount'), 'money'),
            self::card('revenue', 'NguzoPay fees', (int) $q()->sum('platform_fees'), 'money'),
            self::card('net', 'Owed to merchants', (int) $q()->sum('net_amount'), 'money'),
            self::card('pending', 'To be paid out', (int) $q()->where('status', 'pending_payout')->sum('net_amount'), 'money'),
        ];
    }

    public function columns(): array
    {
        $columns = parent::columns();
        $columns['net'] = ['Paid to merchant', 'money'];

        return ['business' => ['Business', 'text'], ...$columns];
    }

    /** @param  Settlement  $s */
    public function row(Model $s): array
    {
        return ['business' => self::businessName($s->business), ...parent::row($s)];
    }
}
