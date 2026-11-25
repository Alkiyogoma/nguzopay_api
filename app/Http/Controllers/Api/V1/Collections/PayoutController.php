<?php

namespace App\Http\Controllers\Api\V1\Collections;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Enums\MobileOperator;
use App\Domain\Collections\Reports\PayoutsReport;
use App\Domain\Payouts\Models\Payout;
use App\Domain\Payouts\Models\SettlementAccount;
use App\Domain\Payouts\Services\PayoutService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Withdrawals (D-027): the merchant asks; NGUZO finance pays. */
class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    public function index(Request $request, Business $business, PayoutsReport $report): AnonymousResourceCollection
    {
        $this->authorize('viewPayments', $business);
        $query = $report->query($business, $report->filters($request));

        return PayoutResource::collection((clone $query)->paginate(min(100, (int) $request->query('per_page', 25))))
            ->additional(['summary' => $report->summary($query, $business), 'balance' => $this->payouts->balance($business)]);
    }

    public function balance(Business $business): JsonResponse
    {
        $this->authorize('viewPayments', $business);

        return response()->json(['data' => $this->payouts->balance($business)]);
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->authorize('requestPayouts', $business);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'channel' => ['required', Rule::in(['mobile_money', 'bank'])],
            'operator' => ['nullable', Rule::enum(MobileOperator::class)],
            'bank_name' => ['required_if:channel,bank', 'nullable', 'string', 'max:100'],
            'recipient_name' => ['required', 'string', 'max:150'],
            'recipient_account' => ['required', 'string', 'max:40'],
            'narration' => ['nullable', 'string', 'max:140'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);
        $data['idempotency_key'] ??= $request->header('Idempotency-Key');

        $payout = $this->payouts->request($business, $data, $request->user());

        return (new PayoutResource($payout->load('requestedBy')))->response()->setStatusCode($payout->wasRecentlyCreated ? 201 : 200);
    }

    /** Where the daily sweep pays this business (D-029). */
    public function settlementAccount(Business $business): JsonResponse
    {
        $this->authorize('viewPayments', $business);

        return response()->json(['data' => $this->account($business->settlementAccount)]);
    }

    public function updateSettlementAccount(Request $request, Business $business): JsonResponse
    {
        $this->authorize('requestPayouts', $business);
        $data = $request->validate([
            'channel' => ['required', Rule::in(['mobile_money', 'bank'])],
            'operator' => ['nullable', Rule::enum(MobileOperator::class)],
            'bank_name' => ['required_if:channel,bank', 'nullable', 'string', 'max:100'],
            'recipient_name' => ['required', 'string', 'max:150'],
            'recipient_account' => ['required', 'string', 'max:40'],
        ]);

        return response()->json(['data' => $this->account($this->payouts->setSettlementAccount($business, $data, $request->user())->load('updater'))]);
    }

    private function account(?SettlementAccount $a): ?array
    {
        return $a ? [
            'channel' => $a->channel,
            'operator' => $a->operator?->value,
            'operator_label' => $a->operator?->label(),
            'bank_name' => $a->bank_name,
            'account_name' => $a->account_name,
            'account_number' => $a->masked(),
            'updated_by' => $a->updater?->name,
            'updated_at' => $a->updated_at?->toIso8601String(),
        ] : null;
    }

    public function show(Business $business, Payout $payout): PayoutResource
    {
        $this->authorize('viewPayments', $business);

        return new PayoutResource($payout->load('requestedBy', 'processedBy'));
    }

    public function cancel(Request $request, Business $business, Payout $payout): PayoutResource
    {
        $this->authorize('requestPayouts', $business);

        return new PayoutResource($this->payouts->cancel($payout, $request->user()));
    }
}
