<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Domain\Billing\Models\Customer;
use App\Domain\Billing\Services\ControlNumberService;
use App\Domain\Business\Models\Business;
use App\Domain\Collections\Enums\MobileOperator;
use App\Domain\Collections\Models\Payment;
use App\Domain\Collections\Models\PaymentIntent;
use App\Domain\Collections\Services\PaymentIntentService;
use App\Domain\Developers\Models\ApiKey;
use App\Domain\Payouts\Services\PayoutService;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\PaymentIntentResource;
use App\Http\Resources\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The merchant API (D-028): what a merchant's own system may do with an API key.
 * The key fixes the business, so nothing here takes a business id.
 */
class MerchantApiController extends Controller
{
    private function business(Request $request): Business
    {
        return $request->attributes->get('business');
    }

    public function balance(Request $request, PayoutService $payouts): JsonResponse
    {
        $b = $payouts->balance($this->business($request));

        return response()->json(['data' => ['currency' => 'TZS', 'available' => $b['available'], 'reserved' => $b['reserved'], 'owed' => $b['owed']]]);
    }

    public function payments(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'customer_id' => ['nullable', 'string']]);

        return PaymentResource::collection(
            Payment::where('business_id', $this->business($request)->id)->with('customer')
                ->when($f['from'] ?? null, fn ($q, $d) => $q->where('paid_at', '>=', $d))
                ->when($f['to'] ?? null, fn ($q, $d) => $q->where('paid_at', '<=', $d.' 23:59:59'))
                ->when($f['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
                ->latest('paid_at')->paginate(min(100, (int) $request->query('per_page', 25)))
        );
    }

    public function payment(Request $request, string $id): PaymentResource
    {
        return new PaymentResource(Payment::where('business_id', $this->business($request)->id)->with('customer', 'allocations.invoice')->findOrFail($id));
    }

    public function requestPayment(Request $request, PaymentIntentService $intents): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'string'],
            'amount' => ['required', 'integer', 'min:1'],
            'operator' => ['nullable', Rule::enum(MobileOperator::class)],
            'description' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'metadata' => ['nullable', 'array'],
        ]);
        $data['phone'] = $this->phone($request);
        $data['idempotency_key'] ??= $request->header('Idempotency-Key');
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');
        $data['metadata'] = ($data['metadata'] ?? []) + ['api_key' => $key->prefix];

        $intent = $intents->create($this->business($request), $data);

        return (new PaymentIntentResource($intent->load('customer', 'invoice', 'attempts')))->response()->setStatusCode($intent->wasRecentlyCreated ? 201 : 200);
    }

    public function paymentRequest(Request $request, string $id): PaymentIntentResource
    {
        return new PaymentIntentResource(PaymentIntent::where('business_id', $this->business($request)->id)->with('customer', 'invoice', 'attempts', 'payment')->findOrFail($id));
    }

    public function customers(Request $request): AnonymousResourceCollection
    {
        $q = $request->query('q');

        return CustomerResource::collection(
            Customer::where('business_id', $this->business($request)->id)->with('accountNumber', 'group')
                ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('reference', $q)->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', (string) $q).'%')))
                ->latest()->paginate(min(100, (int) $request->query('per_page', 25)))
        );
    }

    public function createCustomer(Request $request, AuditLogger $audit, ControlNumberService $controls): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'reference' => ['nullable', 'string', 'max:64', Rule::unique('customers')->where('business_id', $business->id)],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'metadata' => ['nullable', 'array'],
        ]);
        if ($request->filled('phone')) {
            $data['phone'] = $this->phone($request, 'phone', required: false);
        }

        $customer = new Customer($data);
        $customer->business_id = $business->id;
        $customer->save();
        $controls->forCustomer($customer);
        $audit->record('customer.created', $customer, ['name' => $customer->name, 'via' => 'api'], business: $business);

        return (new CustomerResource($customer->load('accountNumber', 'group')))->response()->setStatusCode(201);
    }
}
