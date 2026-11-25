<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D-029: the T+0 sweep pays each settlement as a payout to the business's settlement account. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('channel', 20);                 // mobile_money | bank
            $table->string('operator', 20)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('account_name', 150);
            $table->string('account_number', 40);
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->string('kind', 12)->default('withdrawal')->after('business_id'); // withdrawal | settlement
            $table->foreignUlid('settlement_id')->nullable()->after('kind')->constrained()->nullOnDelete();
            $table->unique('settlement_id');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropUnique(['settlement_id']);
            $table->dropConstrainedForeignId('settlement_id');
            $table->dropColumn('kind');
        });
        Schema::dropIfExists('settlement_accounts');
    }
};
