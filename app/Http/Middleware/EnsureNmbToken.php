<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** NMB authenticates with the Bearer token agreed during onboarding (NMB doc "API Authentication"). */
class EnsureNmbToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('nguzopay.payments.nmb.bearer_token');
        $given = (string) $request->bearerToken();

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['body' => (object) [], 'message' => 'Unauthorized.', 'statusCode' => 649], 401);
        }

        return $next($request);
    }
}
