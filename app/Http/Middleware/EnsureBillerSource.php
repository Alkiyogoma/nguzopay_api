<?php

namespace App\Http\Middleware;

use App\Domain\Collections\Billers\StandardBillsResult;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bank biller endpoints accept calls only from the bank's VPN addresses.
 * A refusal is answered in the bank's own format so its system can read it.
 */
class EnsureBillerSource
{
    public function handle(Request $request, Closure $next, string $biller): Response
    {
        $allowed = (array) config("nguzopay.payments.{$biller}.allowed_ips");

        if ($allowed && ! in_array($request->ip(), $allowed, true)) {
            return response()->json(match ($biller) {
                'nmb' => ['body' => (object) [], 'message' => 'Access denied.', 'statusCode' => 649],
                'crdb' => StandardBillsResult::error(StandardBillsResult::INVALID_TOKEN, 'Access denied')->toArray(),
                default => ['message' => 'Access denied.'],
            }, 403);
        }

        return $next($request);
    }
}
