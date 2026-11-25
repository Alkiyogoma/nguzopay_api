<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Domain\Collections\Services\PaymentIntentService;
use App\Domain\Collections\Services\PaymentReconciler;
use App\Domain\Collections\Services\SettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schedule;

Artisan::command('nguzopay:payments:reconcile', function (PaymentReconciler $reconciler, PaymentIntentService $intents) {
    $result = $reconciler->run();
    $expired = $intents->expireStale();
    $this->info("Replayed {$result['replayed']}, settled {$result['settled']} from provider status, expired {$expired}.");
})->purpose('Recover ambiguous sends, settle payments from provider status, then expire the rest');

Artisan::command('nguzopay:settlements:close {--date= : Settlement date (YYYY-MM-DD), default today}', function (SettlementService $settlements) {
    $date = $this->option('date')
        ? CarbonImmutable::parse($this->option('date'), config('nguzopay.settlement.timezone'))
        : null;
    $created = $settlements->close($date);
    $this->info($created->count().' settlement(s) created, net '.number_format($created->sum('net_amount')).' TZS.');
})->purpose('Close the T+0 settlement for every business (D-016)');

Schedule::command('nguzopay:payments:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('nguzopay:settlements:close')
    ->dailyAt((string) config('nguzopay.settlement.cutoff'))
    ->timezone((string) config('nguzopay.settlement.timezone'))
    ->withoutOverlapping()->onOneServer();
