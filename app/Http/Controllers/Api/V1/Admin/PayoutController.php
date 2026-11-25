<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Payouts\Models\Payout;
use App\Domain\Payouts\Services\PayoutService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutResource;
use Illuminate\Http\Request;

/** NGUZO finance pays withdrawals by hand until the payout rails (P3), then records the outcome here. */
class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    public function show(Payout $payout): PayoutResource
    {
        return new PayoutResource($payout->load('business', 'requestedBy', 'processedBy'));
    }

    public function processing(Request $request, Payout $payout): PayoutResource
    {
        return new PayoutResource($this->payouts->markProcessing($payout, $request->user())->load('business', 'requestedBy', 'processedBy'));
    }

    public function complete(Request $request, Payout $payout): PayoutResource
    {
        $data = $request->validate(['external_reference' => ['required', 'string', 'max:100']]);

        return new PayoutResource($this->payouts->complete($payout, $data['external_reference'], $request->user())->load('business', 'requestedBy', 'processedBy'));
    }

    public function fail(Request $request, Payout $payout): PayoutResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new PayoutResource($this->payouts->fail($payout, $data['reason'], $request->user())->load('business', 'requestedBy', 'processedBy'));
    }
}
