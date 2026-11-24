<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DEVELOPMENT_PLAN §7: 2FA is mandatory for anyone who moves money or approves merchants.
 * Answers 403 with code "two_factor_required" so the portal can send the user to set it up.
 */
class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->two_factor_enabled) {
            return response()->json([
                'message' => 'Turn on two-step verification in Settings → Security before doing this.',
                'code' => 'two_factor_required',
            ], 403);
        }

        return $next($request);
    }
}
