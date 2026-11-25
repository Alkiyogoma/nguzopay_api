<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every call a bank (NMB, CRDB) makes to our biller endpoints, with our exact
 * answer, including rejected ones. Evidence for disputes and reconciliation
 * ("NMB says we answered 649 and reversed it"). Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biller_calls', function (Blueprint $table) {
            $table->id();
            $table->string('biller', 10);                  // nmb | crdb
            $table->string('endpoint', 100);
            $table->string('kind', 20)->nullable();        // inquiry | payment
            $table->string('transaction_id', 100)->nullable()->index();
            $table->string('payment_reference', 50)->nullable()->index();
            $table->string('bank_reference', 100)->nullable()->index();
            $table->unsignedSmallInteger('http_status');
            $table->string('result_code', 10)->nullable(); // NMB statusCode / CRDB status
            $table->string('result_message', 255)->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['biller', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biller_calls');
    }
};
