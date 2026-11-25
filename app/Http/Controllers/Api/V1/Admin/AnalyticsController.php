<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Platform\Analytics\Period;
use App\Domain\Platform\Analytics\PlatformAnalytics;
use App\Http\Controllers\Api\V1\Collections\OverviewController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __invoke(Request $request, PlatformAnalytics $analytics): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return response()->json(['data' => $analytics->platform(Period::fromInput($data['from'] ?? null, $data['to'] ?? null))]);
    }

    /** The Overview strip for the whole platform (same shape as a business's Home). */
    public function overview(Request $request, PlatformAnalytics $analytics): JsonResponse
    {
        $f = $request->validate(OverviewController::RULES);

        return response()->json(['data' => $analytics->overview(Period::fromPreset($f['period'] ?? null, $f['from'] ?? null, $f['to'] ?? null))]);
    }
}
