<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX rental_checkouts_one_active_per_asset
                 ON rental_checkouts (asset_id)
                 WHERE returned_at IS NULL'
            );

            return;
        }

        if ($driver === 'mysql') {
            // Functional unique index (MySQL 8.0.13+): one non-returned row per asset_id.
            // A stored generated column on this table hit misleading InnoDB error 1215 in practice.
            DB::statement(
                'CREATE UNIQUE INDEX rental_checkouts_one_active_per_asset
                 ON rental_checkouts ((CASE WHEN returned_at IS NULL THEN asset_id END))'
            );
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS rental_checkouts_one_active_per_asset');

            return;
        }

        if ($driver === 'mysql') {
            DB::statement('DROP INDEX rental_checkouts_one_active_per_asset ON rental_checkouts');
        }
    }
};
