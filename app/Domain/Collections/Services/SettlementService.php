<?php

namespace App\Domain\Collections\Services;

use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Models\Settlement;
use App\Domain\Payouts\Services\PayoutService;
use App\Domain\Platform\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * T+0 sweep (D-016). At the daily cut-off, every unsettled payment received up
 * to the cut-off becomes one settlement per business, to be paid to the
 * merchant the same day. Each settlement becomes a payout to the business's
 * settlement account (D-029); finance pays it until the payout rails (P3), and
 * the settlement follows its payout (paid / failed / covered).
 */
class SettlementService
{
    public function __construct(private readonly AuditLogger $audit, private readonly PayoutService $payouts) {}

    public function cutoffFor(CarbonImmutable $date): CarbonImmutable
    {
        $tz = config('nguzopay.settlement.timezone');
        [$h, $m] = array_map('intval', explode(':', (string) config('nguzopay.settlement.cutoff')));

        return CarbonImmutable::parse($date->toDateString(), $tz)->setTime($h, $m)->utc();
    }

    /** @return Collection<int, Settlement> */
    public function close(?CarbonImmutable $date = null): Collection
    {
        $date ??= CarbonImmutable::now(config('nguzopay.settlement.timezone'));
        $cutoff = $this->cutoffFor($date);

        $businessIds = Payment::settleable()->whereNull('settlement_id')
            ->where('paid_at', '<=', $cutoff)
            ->distinct()->pluck('business_id');

        return $businessIds->map(fn (string $businessId) => DB::transaction(function () use ($businessId, $date, $cutoff) {
            if (Settlement::where('business_id', $businessId)->whereDate('settlement_date', $date->toDateString())->exists()) {
                return null; // already closed today; late arrivals roll into tomorrow's sweep
            }

            $payments = Payment::settleable()->where('business_id', $businessId)
                ->whereNull('settlement_id')
                ->where('paid_at', '<=', $cutoff)
                ->lockForUpdate()->get();

            if ($payments->isEmpty()) {
                return null;
            }

            $settlement = Settlement::create([
                'business_id' => $businessId,
                'settlement_date' => $date->toDateString(),
                'cutoff_at' => $cutoff,
                'payments_count' => $payments->count(),
                'gross_amount' => $payments->sum('amount'),
                'provider_fees' => $payments->sum('provider_fee'),
                'platform_fees' => $payments->sum('platform_fee'),
                'net_amount' => $payments->sum('net_amount'),
                'status' => 'pending_payout',
            ]);

            Payment::whereIn('id', $payments->pluck('id'))->update(['settlement_id' => $settlement->id]);

            $this->audit->record('settlement.created', $settlement, [
                'date' => $date->toDateString(),
                'payments' => $settlement->payments_count,
                'net_amount' => $settlement->net_amount,
            ]);

            return $settlement;
        }))->filter()->values()
            // Outside the settlement transaction: a failed payout must never undo the settlement.
            ->each(fn (Settlement $s) => $this->payouts->sweep($s))
            ->tap(fn () => $this->sweepWaiting());
    }

    /** Settlements closed before the business set its settlement account are paid as soon as it has one. */
    private function sweepWaiting(): void
    {
        Settlement::where('status', 'pending_payout')
            ->whereDoesntHave('payout')
            ->whereHas('business.settlementAccount')
            ->orderBy('settlement_date')
            ->each(fn (Settlement $s) => $this->payouts->sweep($s));
    }
}
