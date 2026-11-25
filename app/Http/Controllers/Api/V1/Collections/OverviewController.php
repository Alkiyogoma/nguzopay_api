<?php

namespace App\Http\Controllers\Api\V1\Collections;

use App\Domain\Business\Models\Business;
use App\Domain\Platform\Analytics\Period;
use App\Domain\Platform\Analytics\PlatformAnalytics;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Home overview: Today / 7D / 30D / 90D / All / custom range. */
class OverviewController extends Controller
{
    public const RULES = [
        'period' => ['nullable', 'in:today,7d,30d,90d,all'],
        'from' => ['nullable', 'date'],
        'to' => ['nullable', 'date', 'after_or_equal:from'],
    ];

    public function __invoke(Request $request, Business $business, PlatformAnalytics $analytics): JsonResponse
    {
        $this->authorize('viewPayments', $business);
        $f = $request->validate(self::RULES);

        return response()->json(['data' => $analytics->overview(Period::fromPreset($f['period'] ?? null, $f['from'] ?? null, $f['to'] ?? null), $business->id)]);
    }
}
