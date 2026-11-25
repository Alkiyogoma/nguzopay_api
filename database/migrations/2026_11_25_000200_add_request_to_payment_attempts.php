<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The exact request sent to the provider, so an ambiguous send is replayed byte-for-byte. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->json('request')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', fn (Blueprint $table) => $table->dropColumn('request'));
    }
};
