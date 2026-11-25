<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1 Collections core: billing (customers, invoices, instalments), collections
 * (payment intents, attempts, payments, allocations), provider webhook intake,
 * the double-entry ledger and T+0 settlements. All money is bigint TZS.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Per-business pricing override (null = platform default, config/nguzopay.php pricing).
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedInteger('fee_bps')->nullable()->after('status');
            $table->unsignedBigInteger('fee_fixed')->nullable()->after('fee_bps');
            $table->unsignedInteger('next_invoice_number')->default(1)->after('fee_fixed');
        });

        // ── Billing ─────────────────────────────────────────────────
        Schema::create('customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 64)->nullable();          // merchant's own id: student no., member no.
            $table->string('name', 190);
            $table->string('phone', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->unsignedBigInteger('credit_balance')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'reference']);
            $table->index(['business_id', 'name']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->string('external_reference', 64)->nullable();
            $table->string('description', 255)->nullable();
            $table->char('currency', 3)->default('TZS');
            $table->unsignedBigInteger('amount_due');
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->boolean('allow_partial')->default(true);
            $table->unsignedBigInteger('min_amount')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('open');       // open | partially_paid | paid | void
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->unique(['business_id', 'external_reference']);
            $table->index(['business_id', 'status', 'due_date']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_amount');
            $table->unsignedBigInteger('amount');
            $table->unsignedSmallInteger('position')->default(0);
        });

        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('amount_paid')->default(0);

            $table->unique(['invoice_id', 'sequence']);
        });

        // ── Collections ─────────────────────────────────────────────
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('TZS');
            $table->string('channel', 20)->default('mobile_money');
            $table->string('operator', 20)->nullable();          // mpesa | airtel | tigo | halopesa | azampesa
            $table->string('payer_phone', 20);
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('pending');    // pending | processing | succeeded | failed | expired | cancelled
            $table->string('failure_reason', 255)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'status', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        // One intent can be tried on several providers; each try is an attempt.
        // The attempt id is the reference we send to the provider (ULID, 26 chars).
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_intent_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_reference', 128)->nullable();
            $table->string('status', 20)->default('pending');    // pending | processing | succeeded | failed | expired | cancelled
            $table->string('error_kind', 12)->nullable();        // ambiguous | definite
            $table->string('message', 255)->nullable();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->index(['provider', 'provider_reference']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('payment_intent_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignUlid('payment_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('provider_reference', 128);
            $table->string('channel', 20)->default('mobile_money');
            $table->string('operator', 20)->nullable();
            $table->string('payer_phone', 20)->nullable();
            $table->unsignedBigInteger('amount');                 // gross, what the payer paid
            $table->unsignedBigInteger('provider_fee')->default(0);
            $table->boolean('provider_fee_estimated')->default(false);
            $table->unsignedBigInteger('platform_fee')->default(0);
            $table->unsignedBigInteger('net_amount');             // owed to the merchant (fees absorbed, D-015)
            $table->unsignedBigInteger('allocated_amount')->default(0);
            $table->unsignedBigInteger('credited_amount')->default(0);
            $table->string('status', 20)->default('succeeded');
            $table->foreignUlid('settlement_id')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
            $table->index(['business_id', 'paid_at']);
            $table->index(['business_id', 'settlement_id']);
        });

        // An invoice's balance changes only through allocations (plan §2.4).
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('payment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('source', 10);                         // payment | credit
            $table->unsignedBigInteger('amount');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['invoice_id']);
        });

        // Raw provider webhooks, kept for audit and de-duplicated per provider.
        Schema::create('inbound_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_key', 190)->nullable();         // null when the signature failed
            $table->string('event_type', 60)->nullable();
            $table->boolean('signature_ok');
            $table->json('payload');
            $table->string('status', 20)->default('received');   // received | processed | ignored | unmatched | rejected | failed
            $table->string('error', 255)->nullable();
            $table->foreignUlid('payment_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_key']);
            $table->index(['status', 'created_at']);
        });

        // T+0 sweep (D-016): one settlement per business per day.
        Schema::create('settlements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->date('settlement_date');
            $table->timestamp('cutoff_at');
            $table->unsignedInteger('payments_count');
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('provider_fees');
            $table->unsignedBigInteger('platform_fees');
            $table->unsignedBigInteger('net_amount');
            $table->string('status', 20)->default('pending_payout'); // pending_payout | paid | failed
            $table->string('payout_reference', 128)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'settlement_date']);
        });

        // ── Ledger (double entry, append-only) ──────────────────────
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();                // e.g. merchant_payable:{business}, provider_clearing:snippe
            $table->string('type', 12);                          // asset | liability | revenue | expense
            $table->foreignUlid('business_id')->nullable()->constrained()->nullOnDelete();
            $table->char('currency', 3)->default('TZS');
            $table->bigInteger('balance')->default(0);          // cached, in the account's normal direction
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);                          // payment.captured, …
            $table->string('source_type', 40);
            $table->string('source_id', 40);
            $table->string('description', 255)->nullable();
            $table->timestamp('posted_at');

            // A source posts each kind of entry at most once: replays cannot double-count.
            $table->unique(['source_type', 'source_id', 'kind']);
        });

        Schema::create('ledger_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained();
            $table->unsignedBigInteger('debit')->default(0);
            $table->unsignedBigInteger('credit')->default(0);

            $table->index('ledger_account_id');
        });
    }

    public function down(): void
    {
        foreach (['ledger_lines', 'ledger_entries', 'ledger_accounts', 'settlements', 'inbound_events', 'payment_allocations',
            'payments', 'payment_attempts', 'payment_intents', 'installments', 'invoice_lines', 'invoices', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['fee_bps', 'fee_fixed', 'next_invoice_number']);
        });
    }
};
