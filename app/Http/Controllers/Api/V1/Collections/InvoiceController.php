<?php

namespace App\Http\Controllers\Api\V1\Collections;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceBatch;
use App\Domain\Billing\Services\InvoiceService;
use App\Domain\Business\Models\Business;
use App\Domain\Collections\Reports\InvoicesReport;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request, Business $business, InvoicesReport $report): AnonymousResourceCollection
    {
        $this->authorize('viewPayments', $business);
        $query = $report->query($business, $report->filters($request));

        return InvoiceResource::collection((clone $query)->paginate(min(100, (int) $request->query('per_page', 25))))
            ->additional(['summary' => $report->summary($query, $business)]);
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->authorize('collect', $business);

        $data = $request->validate([
            'customer_id' => ['required', 'string', Rule::exists('customers', 'id')->where('business_id', $business->id)],
            'external_reference' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'allow_partial' => ['sometimes', 'boolean'],
            'min_amount' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_amount' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'installments' => ['sometimes', 'array', 'max:24'],
            'installments.*.amount' => ['required', 'integer', 'min:1'],
            'installments.*.due_date' => ['nullable', 'date'],
        ]);

        $invoice = $this->invoices->create($business, $data, $request->user());

        return (new InvoiceResource($invoice->load('customer', 'lines', 'installments', 'controlNumber')))
            ->response()->setStatusCode($invoice->wasRecentlyCreated ? 201 : 200);
    }

    /** One invoice for many customers: a whole group or chosen members (customer_ids). */
    public function bulk(Request $request, Business $business): JsonResponse
    {
        $this->authorize('collect', $business);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'group_id' => ['nullable', 'string', Rule::exists('customer_groups', 'id')->where('business_id', $business->id)],
            'customer_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'customer_ids.*' => ['string'],
            'description' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'allow_partial' => ['sometimes', 'boolean'],
            'min_amount' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_amount' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'installments' => ['sometimes', 'array', 'max:24'],
            'installments.*.amount' => ['required', 'integer', 'min:1'],
            'installments.*.due_date' => ['nullable', 'date'],
        ]);

        $result = $this->invoices->createBatch($business, $data, $request->user());

        return response()->json(['data' => [
            'batch' => ['id' => $result['batch']->id, 'name' => $result['batch']->name, 'invoices_count' => $result['batch']->invoices_count, 'total_amount' => $result['batch']->total_amount],
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]], 201);
    }

    public function batches(Business $business): JsonResponse
    {
        $this->authorize('viewPayments', $business);

        return response()->json(['data' => InvoiceBatch::where('business_id', $business->id)->with('group:id,name')->latest()->limit(100)->get()
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'group' => $b->group?->name, 'invoices_count' => $b->invoices_count, 'total_amount' => $b->total_amount, 'created_at' => $b->created_at?->toIso8601String()])]);
    }

    public function show(Business $business, Invoice $invoice): InvoiceResource
    {
        $this->authorize('viewPayments', $business);

        return new InvoiceResource($invoice->load('customer', 'lines', 'installments', 'allocations.installment', 'controlNumber'));
    }

    public function void(Request $request, Business $business, Invoice $invoice): InvoiceResource
    {
        $this->authorize('manageBilling', $business);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new InvoiceResource($this->invoices->void($invoice, $data['reason'], $request->user())->load('customer'));
    }

    public function applyCredit(Request $request, Business $business, Invoice $invoice): JsonResponse
    {
        $this->authorize('manageBilling', $business);
        $data = $request->validate(['amount' => ['nullable', 'integer', 'min:1']]);

        $applied = $this->invoices->applyCredit($invoice, $data['amount'] ?? null, $request->user());

        return response()->json([
            'applied' => $applied,
            'data' => new InvoiceResource($invoice->refresh()->load('customer', 'lines', 'installments', 'allocations.installment', 'controlNumber')),
        ]);
    }
}
