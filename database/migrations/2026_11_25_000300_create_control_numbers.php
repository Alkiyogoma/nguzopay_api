<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control numbers (plan §2.5): the reference a payer quotes at a bank
 * (CRDB now, NMB next). Per invoice, or per customer account (always
 * resolves to the current outstanding balance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedInteger('next_control_sequence')->default(1)->after('next_invoice_number');
        });

        Schema::create('control_numbers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20)->unique();
            $table->string('type', 10);                            // invoice | customer
            $table->foreignUlid('invoice_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('active');       // active | disabled
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'type']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_number', 24)->nullable()->unique()->after('id');
            $table->foreignUlid('control_number_id')->nullable()->after('payment_attempt_id')->constrained()->nullOnDelete();
            $table->string('payer_name', 190)->nullable()->after('payer_phone');
            $table->json('details')->nullable()->after('status'); // bank channel, payment type, transaction date…
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('control_number_id');
            $table->dropColumn(['receipt_number', 'payer_name', 'details']);
        });
        Schema::dropIfExists('control_numbers');
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('next_control_sequence'));
    }
};
