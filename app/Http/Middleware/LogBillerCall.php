<?php

namespace App\Http\Middleware;

use App\Domain\Collections\Models\BillerCall;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records every bank call to a biller endpoint with our exact answer, including
 * calls refused by the IP or token checks (it runs outside them). Ported from
 * sadaka's production bank_integration_logs. Logging never breaks the answer.
 */
class LogBillerCall
{
    public function handle(Request $request, Closure $next, string $biller): Response
    {
        $started = hrtime(true);
        $response = $next($request);

        try {
            $this->record($biller, $request, $response, (int) ((hrtime(true) - $started) / 1_000_000));
        } catch (Throwable $e) {
            Log::error('Could not record biller call', ['biller' => $biller, 'error' => $e->getMessage()]);
        }

        return $response;
    }

    private function record(string $biller, Request $request, Response $response, int $durationMs): void
    {
        $in = json_decode($request->getContent(), true);
        $out = json_decode((string) $response->getContent(), true);
        $in = is_array($in) ? $in : ['raw' => mb_substr($request->getContent(), 0, 2000)];
        $out = is_array($out) ? $out : null;

        [$kind, $transaction, $reference, $bankReference] = match ($biller) {
            'nmb' => isset($in['requestDetails'])
                ? ['payment', Arr::get($in, 'requestDetails.transactionId'), Arr::get($in, 'requestDetails.paymentReference'), Arr::get($in, 'requestDetails.bankReference')]
                : ['inquiry', Arr::get($in, 'body.transactionId'), Arr::get($in, 'body.paymentReference'), null],
            'crdb' => isset($in['transactionRef'])
                ? ['payment', $in['transactionRef'], $in['paymentReference'] ?? null, null]
                : ['inquiry', null, $in['paymentReference'] ?? null, null],
            default => [null, null, null, null],
        };

        BillerCall::create([
            'biller' => $biller,
            'endpoint' => mb_substr($request->path(), 0, 100),
            'kind' => $kind,
            'transaction_id' => $this->text($transaction, 100),
            'payment_reference' => $this->text($reference, 50),
            'bank_reference' => $this->text($bankReference, 100),
            'http_status' => $response->getStatusCode(),
            'result_code' => $this->text($out['statusCode'] ?? $out['status'] ?? null, 10),
            'result_message' => $this->text($out['message'] ?? $out['statusDesc'] ?? null, 255),
            'request' => $in,
            'response' => $out,
            'ip_address' => $request->ip(),
            'user_agent' => $this->text($request->userAgent(), 255),
            'duration_ms' => $durationMs,
        ]);
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, $max) : null;
    }
}
