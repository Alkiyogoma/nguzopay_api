<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Customer groups: a school's classes, a church's fellowships, an MFI's centres. One group per customer. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignUlid('group_id')->nullable()->after('business_id')->constrained('customer_groups')->nullOnDelete();
            $table->index(['business_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
        });
        Schema::dropIfExists('customer_groups');
    }
};
