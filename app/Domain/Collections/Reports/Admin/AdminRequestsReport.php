<?php

namespace App\Domain\Collections\Reports\Admin;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\PaymentIntent;
use App\Domain\Collections\Reports\RequestsReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Every mobile-money prompt on the platform: success rate by provider is the health signal. */
class AdminRequestsReport extends RequestsReport
{
    use AcrossBusinesses;

    public function title(): string
    {
        return 'All payment requests';
    }

    protected function base(?Business $business): Builder
    {
        return PaymentIntent::query()->with('customer', 'invoice', 'business', 'attempts')->latest();
    }

    protected function rules(): array
    {
        return [...parent::rules(), ...$this->businessRules(), 'provider' => ['nullable', Rule::in(['mock', 'snippe', 'azampay', 'selcom'])]];
    }

    protected function filter(Builder $query, array $f): void
    {
        parent::filter($query, $f);
        $this->filterBusinesses($query, $f, 'payment_intents.business_id');
        if (! empty($f['provider'])) {
            $query->whereHas('attempts', fn ($a) => $a->where('provider', $f['provider']));
        }
    }

    protected function describe(array $f): array
    {
        return [...$this->describeBusinesses($f), ...parent::describe($f), ...(! empty($f['provider']) ? ['Provider: '.ucfirst($f['provider'])] : [])];
    }

    public function columns(): array
    {
        return ['business' => ['Business', 'text'], 'provider' => ['Provider', 'text'], ...parent::columns()];
    }

    /** @param  PaymentIntent  $i */
    public function row(Model $i): array
    {
        return ['business' => self::businessName($i->business), 'provider' => $i->attempts->sortByDesc('id')->first()?->provider, ...parent::row($i)];
    }
}
