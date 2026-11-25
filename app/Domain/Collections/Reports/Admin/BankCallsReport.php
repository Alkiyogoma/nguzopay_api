<?php

namespace App\Domain\Collections\Reports\Admin;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\BillerCall;
use App\Domain\Collections\Reports\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** The biller_calls log (D-025): every NMB/CRDB call and our answer, for disputes. */
class BankCallsReport extends Report
{
    private const OK = ['nmb' => '600', 'crdb' => '200'];

    public function title(): string
    {
        return 'Bank calls';
    }

    protected function base(?Business $business): Builder
    {
        return BillerCall::query()->latest('id');
    }

    protected function dateColumn(): string
    {
        return 'biller_calls.created_at';
    }

    protected function rules(): array
    {
        return [
            'bank' => ['nullable', Rule::in(['nmb', 'crdb'])],
            'kind' => ['nullable', Rule::in(['inquiry', 'payment'])],
            'outcome' => ['nullable', Rule::in(['ok', 'refused'])],
        ];
    }

    protected function filter(Builder $query, array $f): void
    {
        if (! empty($f['bank'])) {
            $query->where('biller', $f['bank']);
        }
        if (! empty($f['kind'])) {
            $query->where('kind', $f['kind']);
        }
        if (! empty($f['outcome'])) {
            $ok = fn ($w) => $w->where(fn ($x) => $x->where('biller', 'nmb')->where('result_code', self::OK['nmb']))
                ->orWhere(fn ($x) => $x->where('biller', 'crdb')->where('result_code', self::OK['crdb']));
            $f['outcome'] === 'ok' ? $query->where($ok) : $query->whereNot($ok);
        }
        if (! empty($f['q'])) {
            $like = self::like($f['q']);
            $query->where(fn ($w) => $w->where('transaction_id', 'like', $like)->orWhere('payment_reference', 'like', $like)->orWhere('bank_reference', 'like', $like));
        }
    }

    protected function describe(array $f): array
    {
        return array_values(array_filter([
            ! empty($f['bank']) ? 'Bank: '.strtoupper($f['bank']) : null,
            ! empty($f['kind']) ? 'Type: '.ucfirst($f['kind']) : null,
            ! empty($f['outcome']) ? 'Outcome: '.($f['outcome'] === 'ok' ? 'Accepted' : 'Refused') : null,
        ]));
    }

    public function summary(Builder $filtered, ?Business $business): array
    {
        $q = fn () => (clone $filtered)->reorder();
        $total = $q()->count();
        $ok = $q()->where(fn ($w) => $w->where(fn ($x) => $x->where('biller', 'nmb')->where('result_code', '600'))
            ->orWhere(fn ($x) => $x->where('biller', 'crdb')->where('result_code', '200')))->count();

        return [
            self::card('calls', 'Calls', $total),
            self::card('payments', 'Payment notifications', $q()->where('kind', 'payment')->count()),
            self::card('refused', 'Refused', $total - $ok),
            self::card('speed', 'Average answer (ms)', (int) round((float) $q()->avg('duration_ms'))),
        ];
    }

    public function columns(): array
    {
        return [
            'at' => ['Time', 'datetime'],
            'bank' => ['Bank', 'text'],
            'kind' => ['Type', 'text'],
            'reference' => ['Control number', 'text'],
            'transaction' => ['Transaction', 'text'],
            'bank_reference' => ['Bank reference', 'text'],
            'code' => ['Our answer', 'text'],
            'message' => ['Message', 'text'],
            'http' => ['HTTP', 'number'],
            'ip' => ['IP', 'text'],
            'ms' => ['ms', 'number'],
        ];
    }

    /** @param  BillerCall  $c */
    public function row(Model $c): array
    {
        return [
            'at' => $c->created_at,
            'bank' => strtoupper($c->biller),
            'kind' => $c->kind ? ucfirst($c->kind) : null,
            'reference' => $c->payment_reference,
            'transaction' => $c->transaction_id,
            'bank_reference' => $c->bank_reference,
            'code' => $c->result_code,
            'message' => $c->result_message,
            'http' => $c->http_status,
            'ip' => $c->ip_address,
            'ms' => $c->duration_ms,
        ];
    }

    public static function accepted(BillerCall $c): bool
    {
        return ($c->result_code ?? null) === (self::OK[$c->biller] ?? null);
    }
}
