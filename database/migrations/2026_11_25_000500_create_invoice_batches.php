<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One bulk run of invoices (e.g. "Term 1 2027 fees" for Form 2B). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            // Client-generated key: a retried or double-clicked submit reuses the same batch.
            $table->string('idempotency_key', 64);
            $table->foreignUlid('group_id')->nullable()->constrained('customer_groups')->nullOnDelete();
            $table->unsignedInteger('invoices_count')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'idempotency_key']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUlid('batch_id')->nullable()->after('customer_id')->constrained('invoice_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropConstrainedForeignId('batch_id'));
        Schema::dropIfExists('invoice_batches');
    }
};
