<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Printed invoices and receipts carry a QR code to a public page for that one document. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'payments'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('public_token', 40)->nullable()->unique());

            DB::table($table)->whereNull('public_token')->orderBy('id')->select('id')->chunk(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['public_token' => Str::random(32)]);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'payments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropUnique(['public_token']);
                $t->dropColumn('public_token');
            });
        }
    }
};
