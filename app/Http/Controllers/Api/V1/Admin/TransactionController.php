<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Collections\Models\BillerCall;
use App\Domain\Collections\Reports\Admin\AdminPaymentsReport;
use App\Domain\Collections\Reports\Admin\AdminPayoutsReport;
use App\Domain\Collections\Reports\Admin\AdminRequestsReport;
use App\Domain\Collections\Reports\Admin\AdminSettlementsReport;
use App\Domain\Collections\Reports\Admin\BankCallsReport;
use App\Domain\Collections\Reports\Admin\BusinessesReport;
use App\Domain\Collections\Reports\Report;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Api\V1\Collections\ReportController;
use App\Http\Resources\PaymentIntentResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\PayoutResource;
use App\Http\Resources\SettlementResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/** Platform-wide transaction lists for NGUZO staff, with the same cards, filters, Print and Export as merchants get. */
class TransactionController extends ReportController
{
    public const ADMIN_REPORTS = [
        'businesses' => BusinessesReport::class,
        'payments' => AdminPaymentsReport::class,
        'requests' => AdminRequestsReport::class,
        'settlements' => AdminSettlementsReport::class,
        'bank-calls' => BankCallsReport::class,
        'payouts' => AdminPayoutsReport::class,
    ];

    public function payments(Request $request, AdminPaymentsReport $report): AnonymousResourceCollection
    {
        return $this->list($request, $report, fn ($page) => PaymentResource::collection($page));
    }

    public function requests(Request $request, AdminRequestsReport $report): AnonymousResourceCollection
    {
        return $this->list($request, $report, fn ($page) => PaymentIntentResource::collection($page));
    }

    public function settlements(Request $request, AdminSettlementsReport $report): AnonymousResourceCollection
    {
        return $this->list($request, $report, fn ($page) => SettlementResource::collection($page));
    }

    public function payouts(Request $request, AdminPayoutsReport $report): AnonymousResourceCollection
    {
        return $this->list($request, $report, fn ($page) => PayoutResource::collection($page));
    }

    public function bankCalls(Request $request, BankCallsReport $report): JsonResponse
    {
        $query = $report->query(null, $report->filters($request));
        $page = (clone $query)->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($page->items())->map(fn (BillerCall $c) => [
                'id' => $c->id,
                'bank' => $c->biller,
                'kind' => $c->kind,
                'endpoint' => $c->endpoint,
                'transaction_id' => $c->transaction_id,
                'payment_reference' => $c->payment_reference,
                'bank_reference' => $c->bank_reference,
                'http_status' => $c->http_status,
                'result_code' => $c->result_code,
                'result_message' => $c->result_message,
                'accepted' => BankCallsReport::accepted($c),
                'request' => $c->request,
                'response' => $c->response,
                'ip_address' => $c->ip_address,
                'duration_ms' => $c->duration_ms,
                'created_at' => $c->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'summary' => $report->summary($query, null),
        ]);
    }

    public function exportAdmin(Request $request, string $report, AuditLogger $audit): Response
    {
        abort_unless(isset(self::ADMIN_REPORTS[$report]), 404);

        return $this->render($request, app(self::ADMIN_REPORTS[$report]), $report, null, $audit);
    }

    private function list(Request $request, Report $report, callable $resource): AnonymousResourceCollection
    {
        $query = $report->query(null, $report->filters($request));

        return $resource((clone $query)->paginate($this->perPage($request)))->additional(['summary' => $report->summary($query, null)]);
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', 25)));
    }
}
