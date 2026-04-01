<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX rental_checkouts_one_active_per_asset
             ON rental_checkouts (asset_id)
             WHERE returned_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS rental_checkouts_one_active_per_asset');
    }
};
