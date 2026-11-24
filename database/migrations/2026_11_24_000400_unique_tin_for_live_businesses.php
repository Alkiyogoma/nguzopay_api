<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One TIN can belong to only one business that is submitted, in review, active
 * or suspended. Drafts and rejected applications may repeat (a business can
 * restart). Partial index: same syntax on PostgreSQL and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX businesses_tin_live_unique ON businesses (tin)
             WHERE tin IS NOT NULL AND deleted_at IS NULL
               AND status IN ('submitted', 'in_review', 'active', 'suspended')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS businesses_tin_live_unique');
    }
};
