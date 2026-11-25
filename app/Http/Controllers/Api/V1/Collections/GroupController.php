<?php

namespace App\Http\Controllers\Api\V1\Collections;

use App\Domain\Billing\Models\Customer;
use App\Domain\Billing\Models\CustomerGroup;
use App\Domain\Business\Models\Business;
use App\Domain\Collections\Reports\GroupsReport;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Customer groups (a school's classes) with paid / outstanding / overdue totals. */
class GroupController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, Business $business, GroupsReport $report): JsonResponse
    {
        $this->authorize('viewPayments', $business);
        $query = $report->query($business, $report->filters($request));

        $groups = (clone $query)->paginate(min(200, (int) $request->query('per_page', 50)));

        return response()->json([
            'data' => $groups->getCollection()->map(fn ($g) => $this->present($g, $report))->all(),
            'meta' => ['current_page' => $groups->currentPage(), 'last_page' => $groups->lastPage(), 'total' => $groups->total(), 'per_page' => $groups->perPage()],
            'links' => ['next' => $groups->nextPageUrl(), 'prev' => $groups->previousPageUrl()],
            'summary' => $report->summary($query, $business),
            'ungrouped' => $business->customers()->whereNull('group_id')->count(),
        ]);
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->authorize('collect', $business);
        $group = $business->customerGroups()->create($this->validated($request, $business));
        $this->audit->record('customer_group.created', $group, ['name' => $group->name], business: $business);

        return response()->json(['data' => ['id' => $group->id, 'name' => $group->name, 'description' => $group->description]], 201);
    }

    public function update(Request $request, Business $business, CustomerGroup $group): JsonResponse
    {
        $this->authorize('collect', $business);
        $group->update($this->validated($request, $business, $group));
        $this->audit->record('customer_group.updated', $group, ['name' => $group->name], business: $business);

        return response()->json(['data' => ['id' => $group->id, 'name' => $group->name, 'description' => $group->description]]);
    }

    /** Deleting a group keeps its customers; they simply have no group. */
    public function destroy(Business $business, CustomerGroup $group): JsonResponse
    {
        $this->authorize('manageBilling', $business);
        $moved = $group->customers()->update(['group_id' => null]);
        $group->delete();
        $this->audit->record('customer_group.deleted', $group, ['name' => $group->name, 'customers' => $moved], business: $business);

        return response()->json(['message' => 'Group deleted.', 'customers' => $moved]);
    }

    /** Put many customers in a group at once (or take them out with group_id = null). */
    public function move(Request $request, Business $business): JsonResponse
    {
        $this->authorize('collect', $business);
        $data = $request->validate([
            'customer_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'customer_ids.*' => ['string'],
            'group_id' => ['nullable', 'string', Rule::exists('customer_groups', 'id')->where('business_id', $business->id)],
        ]);

        $moved = Customer::where('business_id', $business->id)->whereIn('id', $data['customer_ids'])->update(['group_id' => $data['group_id'] ?? null]);
        $this->audit->record('customers.moved_to_group', $business, ['group_id' => $data['group_id'] ?? null, 'customers' => $moved]);

        return response()->json(['moved' => $moved]);
    }

    private function present(CustomerGroup $g, GroupsReport $report): array
    {
        return ['id' => $g->id, 'description' => $g->description] + $report->row($g);
    }

    private function validated(Request $request, Business $business, ?CustomerGroup $group = null): array
    {
        return $request->validate([
            'name' => [$group ? 'sometimes' : 'required', 'string', 'max:100',
                Rule::unique('customer_groups')->where('business_id', $business->id)->ignore($group?->id)],
            'description' => ['nullable', 'string', 'max:255'],
        ], ['name.unique' => 'There is already a group with this name.']);
    }
}
