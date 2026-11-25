<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A merchant withdrawal (D-027). Requested by the merchant, paid by NGUZO finance until the payout rails (P3).
        Schema::create('payouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20)->unique();             // PO-XXXXXXXXXX, shown to people
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fee')->default(0);
            $table->string('channel', 20);                         // mobile_money | bank
            $table->string('operator', 20)->nullable();            // mpesa | airtel | tigo | halopesa | azampesa
            $table->string('bank_name', 100)->nullable();
            $table->string('recipient_name', 150);
            $table->string('recipient_account', 40);               // phone (255…) or bank account number
            $table->string('narration', 140)->nullable();
            $table->string('status', 20)->default('requested');    // requested | processing | completed | failed | cancelled
            $table->string('external_reference', 100)->nullable(); // bank / MNO transaction reference
            $table->string('failure_reason', 255)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        // Merchant API keys (D-028). Only a SHA-256 hash is stored; the secret is shown once.
        Schema::create('api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('prefix', 16);                          // np_sk_ + 8 chars, to recognise a key
            $table->char('token_hash', 64)->unique();
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('payouts');
    }
};
