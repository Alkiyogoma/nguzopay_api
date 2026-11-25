<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Reports\Admin\BusinessesReport;
use App\Domain\Platform\Analytics\Period;
use App\Domain\Platform\Analytics\PlatformAnalytics;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Business management for NGUZO staff: the performance list, one business's performance, and its pricing. */
class BusinessManagementController extends Controller
{
    public function index(Request $request, BusinessesReport $report): JsonResponse
    {
        $filters = $report->filters($request);
        $query = $report->query(null, $filters);
        $page = (clone $query)->paginate(max(1, min(100, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => collect($page->items())->map(fn (Business $b) => [
                'id' => $b->id,
                'code' => $b->code,
                'display_name' => $b->displayName(),
                'legal_name' => $b->legal_name,
                'type' => $b->type?->value,
                'type_label' => $b->type?->label(),
                'status' => $b->status->value,
                'email' => $b->email,
                'phone' => $b->phone,
                'region' => $b->region,
                'owner' => $b->owner?->user ? ['name' => $b->owner->user->name, 'email' => $b->owner->user->email, 'phone' => $b->owner->user->phone] : null,
                'created_at' => $b->created_at?->toIso8601String(),
                'customers_count' => (int) $b->customers_count,
                'payments_count' => (int) $b->payments_count,
                'collected' => (int) $b->collected,
                'revenue' => (int) $b->revenue,
                'outstanding' => (int) $b->outstanding,
                'last_payment_at' => $b->last_payment_at ? CarbonImmutable::parse($b->last_payment_at)->toIso8601String() : null,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'summary' => $report->summary($query, null),
        ]);
    }

    public function performance(Request $request, Business $business, PlatformAnalytics $analytics): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return response()->json([
            'data' => $analytics->business($business, Period::fromInput($data['from'] ?? null, $data['to'] ?? null)),
            'pricing' => $this->pricing($business),
        ]);
    }

    /** NguzoPay's fee for this business (D-015: the merchant absorbs it). Null = the platform default. */
    public function updatePricing(Request $request, Business $business, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'fee_bps' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'fee_fixed' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $before = $this->pricing($business);
        $business->forceFill(['fee_bps' => $data['fee_bps'] ?? null, 'fee_fixed' => $data['fee_fixed'] ?? null])->save();
        $after = $this->pricing($business);

        $audit->record('business.pricing_changed', $business, ['before' => $before, 'after' => $after, 'reason' => $data['reason']]);

        return response()->json(['pricing' => $after, 'business' => new BusinessResource($business)]);
    }

    private function pricing(Business $business): array
    {
        return [
            'fee_bps' => $business->fee_bps,
            'fee_fixed' => $business->fee_fixed,
            'default_fee_bps' => (int) config('nguzopay.pricing.fee_bps'),
            'default_fee_fixed' => (int) config('nguzopay.pricing.fee_fixed'),
            'effective_fee_bps' => $business->fee_bps ?? (int) config('nguzopay.pricing.fee_bps'),
            'effective_fee_fixed' => $business->fee_fixed ?? (int) config('nguzopay.pricing.fee_fixed'),
        ];
    }
}
