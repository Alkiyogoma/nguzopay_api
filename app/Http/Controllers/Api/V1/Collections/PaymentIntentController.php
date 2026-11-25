<?php

namespace App\Http\Controllers\Api\V1\Collections;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Enums\MobileOperator;
use App\Domain\Collections\Models\PaymentIntent;
use App\Domain\Collections\Providers\MockProvider;
use App\Domain\Collections\Providers\ProviderRegistry;
use App\Domain\Collections\Reports\RequestsReport;
use App\Domain\Collections\Services\PaymentIntentService;
use App\Domain\Collections\Services\WebhookIngestor;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentIntentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentIntentController extends Controller
{
    public function __construct(private readonly PaymentIntentService $intents, private readonly ProviderRegistry $providers) {}

    public function index(Request $request, Business $business, RequestsReport $report): AnonymousResourceCollection
    {
        $this->authorize('viewPayments', $business);
        $query = $report->query($business, $report->filters($request));

        return PaymentIntentResource::collection((clone $query)->paginate(min(100, (int) $request->query('per_page', 25))))
            ->additional(['summary' => $report->summary($query, $business)]);
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->authorize('collect', $business);

        $data = $request->validate([
            'invoice_id' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'string'],
            'amount' => ['required', 'integer', 'min:1'],
            'operator' => ['nullable', Rule::enum(MobileOperator::class)],
            'provider' => ['nullable', 'string', Rule::in($this->providers->enabled())],
            'description' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'metadata' => ['nullable', 'array'],
        ]);
        $data['phone'] = $this->phone($request);
        $data['idempotency_key'] ??= $request->header('Idempotency-Key');

        $intent = $this->intents->create($business, $data, $request->user());

        return (new PaymentIntentResource($intent->load('customer', 'invoice', 'attempts')))
            ->response()->setStatusCode($intent->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Business $business, PaymentIntent $paymentIntent): PaymentIntentResource
    {
        $this->authorize('viewPayments', $business);

        return new PaymentIntentResource($paymentIntent->load('customer', 'invoice', 'attempts', 'payment'));
    }

    public function cancel(Request $request, Business $business, PaymentIntent $paymentIntent): PaymentIntentResource
    {
        $this->authorize('collect', $business);

        return new PaymentIntentResource($this->intents->cancel($paymentIntent, $request->user())->load('attempts'));
    }

    public function retry(Request $request, Business $business, PaymentIntent $paymentIntent): PaymentIntentResource
    {
        $this->authorize('collect', $business);
        $data = $request->validate(['provider' => ['nullable', 'string', Rule::in($this->providers->enabled())]]);

        return new PaymentIntentResource($this->intents->retry($paymentIntent, $data['provider'] ?? null, $request->user())->load('attempts'));
    }

    public function resend(Request $request, Business $business, PaymentIntent $paymentIntent): JsonResponse
    {
        $this->authorize('collect', $business);
        $sent = $this->intents->resendPush($paymentIntent, $request->user());

        return response()->json(['sent' => $sent, 'message' => $sent ? 'The prompt was sent to the payer again.' : 'The provider did not accept the resend. Try again shortly.']);
    }

    /** Sandbox only: play the payer's side for a mock-provider request. */
    public function simulate(Request $request, Business $business, PaymentIntent $paymentIntent, WebhookIngestor $ingestor): PaymentIntentResource
    {
        $this->authorize('collect', $business);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['success', 'failure'])],
            'amount' => ['nullable', 'integer', 'min:1'],
        ]);

        $attempt = $paymentIntent->attempts()->reorder('id', 'desc')->first();
        if (! $attempt || $attempt->provider !== 'mock' || ! $this->providers->isEnabled('mock')) {
            throw ValidationException::withMessages(['outcome' => 'Only test-mode (mock) payment requests can be simulated.']);
        }

        $webhook = app(MockProvider::class)->simulate($attempt->load('intent'), $data['outcome'], $data['amount'] ?? null);
        $ingestor->ingest('mock', Request::create('/api/v1/webhooks/mock', 'POST', server: [
            'HTTP_X_MOCK_SIGNATURE' => $webhook['signature'],
            'CONTENT_TYPE' => 'application/json',
        ], content: $webhook['body']));

        return new PaymentIntentResource($paymentIntent->refresh()->load('customer', 'invoice', 'attempts', 'payment'));
    }
}
