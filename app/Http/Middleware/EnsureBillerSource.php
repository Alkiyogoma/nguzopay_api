<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bank biller endpoints accept calls only from the bank's VPN addresses. */
class EnsureBillerSource
{
    public function handle(Request $request, Closure $next, string $biller): Response
    {
        $allowed = (array) config("nguzopay.payments.{$biller}.allowed_ips");

        if ($allowed && ! in_array($request->ip(), $allowed, true)) {
            abort(403);
        }

        return $next($request);
    }
}
