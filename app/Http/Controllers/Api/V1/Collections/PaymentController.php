<?php

namespace App\Http\Controllers\Api\V1\Collections;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Models\PaymentIntent;
use App\Domain\Collections\Reports\PaymentsReport;
use App\Domain\Collections\Reports\SettlementsReport;
use App\Domain\Collections\Services\CollectionsSummary;
use App\Domain\Collections\Services\ManualPaymentService;
use App\Domain\Ledger\Services\Ledger;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\SettlementResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request, Business $business, PaymentsReport $report): AnonymousResourceCollection
    {
        $this->authorize('viewPayments', $business);
        $query = $report->query($business, $report->filters($request));

        return PaymentResource::collection((clone $query)->paginate(min(100, (int) $request->query('per_page', 25))))
            ->additional(['summary' => $report->summary($query, $business)]);
    }

    /** Record a payment received outside NguzoPay (cash, deposit, cheque…). */
    public function store(Request $request, Business $business, ManualPaymentService $manual): JsonResponse
    {
        $this->authorize('manageBilling', $business);

        $data = $request->validate([
            'customer_id' => ['nullable', 'string'],
            'invoice_id' => ['nullable', 'string'],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'paid_on' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'payer_name' => ['nullable', 'string', 'max:190'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = $manual->record($business, $data, $request->user());

        return (new PaymentResource($payment->load('customer', 'allocations.invoice', 'allocations.installment')))->response()->setStatusCode(201);
    }

    public function void(Request $request, Business $business, Payment $payment, ManualPaymentService $manual): PaymentResource
    {
        $this->authorize('manageBilling', $business);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new PaymentResource($manual->void($payment, $data['reason'], $request->user())->load('customer', 'allocations.invoice', 'allocations.installment'));
    }

    public function show(Business $business, Payment $payment): PaymentResource
    {
        $this->authorize('viewPayments', $business);

        return new PaymentResource($payment->load('customer', 'allocations.invoice', 'allocations.installment'));
    }

    public function settlements(Request $request, Business $business, SettlementsReport $report): AnonymousResourceCollection
    {
        $this->authorize('viewPayments', $business);
        $query = $report->query($business, $report->filters($request));

        return SettlementResource::collection((clone $query)->paginate(min(100, (int) $request->query('per_page', 30))))
            ->additional(['summary' => $report->summary($query, $business)]);
    }

    /** Home dashboard: chart series and stat cards. */
    public function summary(Request $request, Business $business, CollectionsSummary $summary): JsonResponse
    {
        $this->authorize('viewPayments', $business);
        $data = $request->validate([
            'metric' => ['nullable', 'in:'.implode(',', CollectionsSummary::METRICS)],
            'period' => ['nullable', 'in:7d,30d,year'],
        ]);

        return response()->json(['data' => $summary->build($business, $data['metric'] ?? 'revenue', $data['period'] ?? '7d')]);
    }

    /** What NguzoPay owes the business right now, and what is waiting to be settled. */
    public function balance(Business $business, Ledger $ledger): JsonResponse
    {
        $this->authorize('viewPayments', $business);

        $unsettled = $business->payments()->settleable()->whereNull('settlement_id');

        return response()->json(['data' => [
            'currency' => 'TZS',
            'payable' => $ledger->balance($ledger->merchantPayable($business)),
            'unsettled_net' => (int) (clone $unsettled)->sum('net_amount'),
            'unsettled_count' => (clone $unsettled)->count(),
            'pending_payout' => (int) $business->settlements()->where('status', 'pending_payout')->sum('net_amount'),
            'awaiting_payer' => PaymentIntent::where('business_id', $business->id)->whereIn('status', ['pending', 'processing'])->count(),
        ]]);
    }
}
