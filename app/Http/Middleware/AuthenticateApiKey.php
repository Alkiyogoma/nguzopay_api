<?php

namespace App\Http\Middleware;

use App\Domain\Developers\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merchant API: "Authorization: Bearer np_sk_…". The key decides the business;
 * each route names the scope it needs (api.key:payments:write).
 */
class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeyService $keys) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = $this->keys->resolve((string) $request->bearerToken());

        if (! $key) {
            return response()->json(['message' => 'Invalid, expired or revoked API key.', 'code' => 'invalid_api_key'], 401);
        }
        if (! $key->allows($scope)) {
            return response()->json(['message' => "This API key does not have the {$scope} scope.", 'code' => 'missing_scope'], 403);
        }
        if ($key->business->status->value === 'suspended') {
            return response()->json(['message' => 'This business is suspended.', 'code' => 'business_suspended'], 403);
        }

        // At most one write a minute per key, so busy integrations don't hammer the table.
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        $request->attributes->set('api_key', $key);
        $request->attributes->set('business', $key->business);

        return $next($request);
    }
}
