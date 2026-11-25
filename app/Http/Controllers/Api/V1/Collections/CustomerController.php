<?php

namespace App\Http\Controllers\Api\V1\Collections;

use App\Domain\Billing\Models\Customer;
use App\Domain\Billing\Services\ControlNumberService;
use App\Domain\Business\Models\Business;
use App\Domain\Collections\Reports\CustomersReport;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, Business $business, CustomersReport $report): AnonymousResourceCollection
    {
        $this->authorize('viewPayments', $business);
        $query = $report->query($business, $report->filters($request));

        $customers = (clone $query)->paginate(min(100, (int) $request->query('per_page', 25)));
        $customers->getCollection()->each(fn ($c) => $c->outstanding = (int) $c->due_sum - (int) $c->paid_sum);

        return CustomerResource::collection($customers)->additional(['summary' => $report->summary($query, $business)]);
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->authorize('collect', $business);
        $data = $this->validated($request, $business);

        $customer = new Customer($data);
        $customer->business_id = $business->id;
        $customer->save();
        app(ControlNumberService::class)->forCustomer($customer);
        $this->audit->record('customer.created', $customer, ['name' => $customer->name], business: $business);

        return (new CustomerResource($customer->load('accountNumber', 'group')))->response()->setStatusCode(201);
    }

    public function show(Business $business, Customer $customer): CustomerResource
    {
        $this->authorize('viewPayments', $business);

        return new CustomerResource($customer->load('accountNumber', 'group'));
    }

    public function update(Request $request, Business $business, Customer $customer): CustomerResource
    {
        $this->authorize('collect', $business);
        $customer->update($this->validated($request, $business, $customer));
        $this->audit->record('customer.updated', $customer, ['fields' => array_keys($customer->getChanges())], business: $business);

        return new CustomerResource($customer);
    }

    private function validated(Request $request, Business $business, ?Customer $customer = null): array
    {
        $required = $customer ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:190'],
            'reference' => ['nullable', 'string', 'max:64',
                Rule::unique('customers')->where('business_id', $business->id)->ignore($customer?->id)],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'metadata' => ['nullable', 'array'],
            'group_id' => ['nullable', 'string', Rule::exists('customer_groups', 'id')->where('business_id', $business->id)],
        ]);

        if ($request->has('phone')) {
            $data['phone'] = $this->phone($request, 'phone', required: false);
        }

        return $data;
    }
}
