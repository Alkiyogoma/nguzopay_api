<?php

namespace App\Domain\Collections\Services;

use App\Domain\Billing\Models\Customer;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Business\Models\Business;
use App\Domain\Collections\Enums\IntentStatus;
use App\Domain\Collections\Enums\MobileOperator;
use App\Domain\Collections\Jobs\InitiatePaymentAttempt;
use App\Domain\Collections\Models\PaymentIntent;
use App\Domain\Collections\Providers\ProviderRegistry;
use App\Domain\Collections\Providers\RecoverableProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentIntentService
{
    public function __construct(private readonly ProviderRegistry $providers, private readonly AuditLogger $audit) {}

    /**
     * Ask a payer to pay by mobile-money push. Returns at once; the push is
     * sent by a queued job and the result arrives by webhook.
     *
     * @param  array{invoice_id?: ?string, customer_id?: ?string, amount: int, phone: string, operator?: ?string, provider?: ?string, description?: ?string, idempotency_key?: ?string, metadata?: ?array}  $data
     */
    public function create(Business $business, array $data, ?User $actor = null): PaymentIntent
    {
        if (! empty($data['idempotency_key'])) {
            $existing = $business->paymentIntents()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }
        }

        if (! $business->canCollect()) {
            throw ValidationException::withMessages(['business' => 'Collections open once NguzoPay has verified this business.']);
        }

        $amount = (int) $data['amount'];
        [$invoice, $customer] = $this->target($business, $data, $amount);

        $provider = $data['provider'] ?? $this->providers->default();
        if (! $this->providers->isEnabled($provider)) {
            throw ValidationException::withMessages(['provider' => 'That payment provider is not available.']);
        }
        $adapter = $this->providers->get($provider);

        ['min' => $min, 'max' => $max] = $adapter->limits();
        if ($amount < $min || $amount > $max) {
            throw ValidationException::withMessages(['amount' => 'Mobile-money payments must be between '.number_format($min).' and '.number_format($max).' TZS.']);
        }

        $operator = isset($data['operator']) ? MobileOperator::from($data['operator']) : MobileOperator::fromPhone($data['phone']);
        if (! $operator && $adapter->requiresOperator()) {
            throw ValidationException::withMessages(['operator' => "Choose the payer's mobile network."]);
        }

        $intent = DB::transaction(function () use ($business, $data, $amount, $invoice, $customer, $operator, $provider, $actor) {
            $intent = new PaymentIntent([
                'amount' => $amount,
                'operator' => $operator,
                'payer_phone' => $data['phone'],
                'description' => $data['description'] ?? ($invoice ? "Invoice {$invoice->number}" : null),
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'expires_at' => now()->addMinutes((int) config('nguzopay.payments.intent_ttl_minutes')),
            ]);
            $intent->forceFill([
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'invoice_id' => $invoice?->id,
                'created_by' => $actor?->id,
            ])->save();

            $attempt = $intent->attempts()->create(['provider' => $provider]);

            $this->audit->record('payment_intent.created', $intent, [
                'amount' => $amount, 'provider' => $provider, 'invoice' => $invoice?->number,
            ], actor: $actor, business: $business);

            InitiatePaymentAttempt::dispatch($attempt->id)->afterCommit();

            return $intent;
        });

        return $intent->refresh();
    }

    /** Try a failed or expired intent again, on the same or another provider. */
    public function retry(PaymentIntent $intent, ?string $provider, User $actor): PaymentIntent
    {
        if (! in_array($intent->status, [IntentStatus::Failed, IntentStatus::Expired], true)) {
            throw ValidationException::withMessages(['status' => 'Only a failed or expired request can be sent again.']);
        }

        $provider ??= $intent->attempts()->reorder('id', 'desc')->value('provider') ?? $this->providers->default();
        if (! $this->providers->isEnabled($provider)) {
            throw ValidationException::withMessages(['provider' => 'That payment provider is not available.']);
        }

        DB::transaction(function () use ($intent, $provider, $actor) {
            $intent->forceFill([
                'status' => IntentStatus::Pending,
                'failure_reason' => null,
                'expires_at' => now()->addMinutes((int) config('nguzopay.payments.intent_ttl_minutes')),
            ])->save();

            $attempt = $intent->attempts()->create(['provider' => $provider]);
            $this->audit->record('payment_intent.retried', $intent, ['provider' => $provider], actor: $actor);

            InitiatePaymentAttempt::dispatch($attempt->id)->afterCommit();
        });

        return $intent->refresh();
    }

    public function cancel(PaymentIntent $intent, User $actor): PaymentIntent
    {
        if (! $intent->status->isOpen()) {
            throw ValidationException::withMessages(['status' => 'This request is already finished.']);
        }

        $intent->forceFill(['status' => IntentStatus::Cancelled, 'failure_reason' => 'Cancelled by '.$actor->name])->save();
        $intent->attempts()->whereIn('status', ['pending', 'processing'])->update(['status' => IntentStatus::Cancelled->value]);
        $this->audit->record('payment_intent.cancelled', $intent, actor: $actor);

        return $intent;
    }

    /**
     * Mark open intents past their expiry. A later success webhook still records the payment.
     * A payment the provider still holds open (Snippe: 4 hours) is not expired by age alone:
     * expiring it would allow a retry, and the payer could then pay twice.
     */
    public function expireStale(): int
    {
        $count = 0;

        PaymentIntent::whereIn('status', [IntentStatus::Pending, IntentStatus::Processing])
            ->where('expires_at', '<', now())
            ->chunkById(200, function ($intents) use (&$count) {
                foreach ($intents as $intent) {
                    if ($this->providerStillOpen($intent)) {
                        continue;
                    }
                    $intent->forceFill(['status' => IntentStatus::Expired, 'failure_reason' => 'The payer did not confirm in time.'])->save();
                    $intent->attempts()->whereIn('status', ['pending', 'processing'])->update(['status' => IntentStatus::Expired->value]);
                    $count++;
                }
            });

        return $count;
    }

    /** Send the payer's phone prompt again, when the provider supports it. */
    public function resendPush(PaymentIntent $intent, User $actor): bool
    {
        $attempt = $intent->attempts()->reorder('id', 'desc')->first();

        if ($intent->status !== IntentStatus::Processing || ! $attempt?->provider_reference) {
            throw ValidationException::withMessages(['status' => 'There is no waiting payment request to resend.']);
        }

        $provider = $this->providers->get($attempt->provider);
        if (! $provider instanceof RecoverableProvider) {
            throw ValidationException::withMessages(['status' => 'This provider cannot resend the prompt. Cancel and request again.']);
        }

        $sent = $provider->resendPush($attempt, $intent);
        $this->audit->record('payment_intent.push_resent', $intent, ['sent' => $sent], actor: $actor);

        return $sent;
    }

    private function providerStillOpen(PaymentIntent $intent): bool
    {
        $attempt = $intent->attempts()->reorder('id', 'desc')->first();
        if (! $attempt || ! $this->providers->isEnabled($attempt->provider)) {
            return false;
        }

        $provider = $this->providers->get($attempt->provider);

        return $provider instanceof RecoverableProvider
            && $attempt->created_at->gt(now()->subMinutes($provider->maxPendingMinutes()));
    }

    /** @return array{0: ?Invoice, 1: Customer} */
    private function target(Business $business, array $data, int $amount): array
    {
        if (! empty($data['invoice_id'])) {
            $invoice = $business->invoices()->with('customer')->find($data['invoice_id']);
            if (! $invoice) {
                throw ValidationException::withMessages(['invoice_id' => 'Invoice not found.']);
            }
            if (! $invoice->status->acceptsPayment()) {
                throw ValidationException::withMessages(['invoice_id' => 'This invoice is not open for payment.']);
            }
            if ($amount < $invoice->minimumPayment()) {
                throw ValidationException::withMessages(['amount' => $invoice->allow_partial
                    ? 'The minimum payment on this invoice is '.number_format($invoice->minimumPayment()).' TZS.'
                    : 'This invoice must be paid in full ('.number_format($invoice->balance()).' TZS).']);
            }

            return [$invoice, $invoice->customer];
        }

        $customer = ! empty($data['customer_id']) ? $business->customers()->find($data['customer_id']) : null;
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => 'Choose an invoice or a customer.']);
        }

        return [null, $customer];
    }
}
