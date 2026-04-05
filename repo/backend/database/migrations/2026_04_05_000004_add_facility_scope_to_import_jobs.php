<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->json('facility_scope_json')->nullable()->after('summary_json');
        });
    }

    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropColumn('facility_scope_json');
        });
    }
};
