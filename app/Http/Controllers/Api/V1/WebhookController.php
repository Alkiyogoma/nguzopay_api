<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Collections\Providers\ProviderRegistry;
use App\Domain\Collections\Services\WebhookIngestor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Provider callbacks. Public: authenticity comes from each provider's signature. */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, ProviderRegistry $providers, WebhookIngestor $ingestor): JsonResponse
    {
        abort_unless($providers->isEnabled($provider), 404);

        $result = $ingestor->ingest($provider, $request);

        return response()->json(['status' => $result['status']], $result['http']);
    }
}
