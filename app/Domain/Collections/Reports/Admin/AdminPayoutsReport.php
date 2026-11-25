<?php

namespace App\Domain\Collections\Reports\Admin;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Reports\PayoutsReport;
use App\Domain\Payouts\Models\Payout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Every withdrawal on the platform: NGUZO finance's work queue. */
class AdminPayoutsReport extends PayoutsReport
{
    use AcrossBusinesses;

    public function title(): string
    {
        return 'All withdrawals';
    }

    protected function base(?Business $business): Builder
    {
        // Open requests first (oldest first: pay in order), then the rest newest first.
        return Payout::query()->with('business', 'requestedBy', 'processedBy')
            ->orderByRaw("case when payouts.status in ('requested','processing') then 0 else 1 end")
            ->orderByRaw("case when payouts.status in ('requested','processing') then payouts.created_at end asc")
            ->latest();
    }

    protected function rules(): array
    {
        return [...parent::rules(), ...$this->businessRules()];
    }

    protected function filter(Builder $query, array $f): void
    {
        parent::filter($query, $f);
        $this->filterBusinesses($query, $f, 'payouts.business_id');
    }

    protected function describe(array $f): array
    {
        return [...$this->describeBusinesses($f), ...parent::describe($f)];
    }

    public function summary(Builder $filtered, ?Business $business): array
    {
        $q = fn () => (clone $filtered)->reorder();

        return [
            self::card('to_pay', 'To pay', (int) $q()->whereIn('payouts.status', Payout::OPEN)->sum('amount'), 'money'),
            self::card('requests', 'Waiting requests', $q()->whereIn('payouts.status', Payout::OPEN)->count()),
            self::card('paid', 'Paid out', (int) $q()->where('payouts.status', 'completed')->sum('amount'), 'money'),
            self::card('fees', 'Withdrawal fees', (int) $q()->where('payouts.status', 'completed')->sum('fee'), 'money'),
        ];
    }

    public function columns(): array
    {
        $columns = parent::columns();
        $columns['account'] = ['Account', 'text'];

        return ['business' => ['Business', 'text'], ...$columns];
    }

    /** @param  Payout  $p */
    public function row(Model $p): array
    {
        // Finance needs the full account to pay; the export is audited.
        return ['business' => self::businessName($p->business), ...parent::row($p), 'account' => $p->recipient_account];
    }
}
