<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('facilities', 'external_key')) {
            Schema::table('facilities', function (Blueprint $table) {
                $table->string('external_key')->nullable()->index()->after('code');
            });
        }

        if (! Schema::hasColumn('facilities', 'version')) {
            Schema::table('facilities', function (Blueprint $table) {
                $table->unsignedInteger('version')->default(1)->after('external_key');
            });
        }

        if (! Schema::hasColumn('facilities', 'reference_url')) {
            Schema::table('facilities', function (Blueprint $table) {
                $table->string('reference_url', 1024)->nullable()->after('version');
            });
        }

        foreach (['departments', 'providers', 'services', 'inventory_items'] as $tableName) {
            if (Schema::hasColumn($tableName, 'reference_url')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->string('reference_url', 1024)->nullable();
            });
        }

        if (! Schema::hasColumn('content_items', 'external_key')) {
            Schema::table('content_items', function (Blueprint $table) {
                $table->string('external_key')->nullable()->index()->after('content_type');
            });
        }

        if (! Schema::hasColumn('content_items', 'version')) {
            Schema::table('content_items', function (Blueprint $table) {
                $table->unsignedInteger('version')->default(1)->after('current_version');
            });
        }

        if (! Schema::hasColumn('content_items', 'reference_url')) {
            Schema::table('content_items', function (Blueprint $table) {
                $table->string('reference_url', 1024)->nullable()->after('body');
            });
        }
    }

    public function down(): void
    {
        foreach (['content_items', 'inventory_items', 'services', 'providers', 'departments', 'facilities'] as $tableName) {
            if (Schema::hasColumn($tableName, 'reference_url')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('reference_url');
                });
            }
        }

        if (Schema::hasColumn('content_items', 'version')) {
            Schema::table('content_items', function (Blueprint $table) {
                $table->dropColumn('version');
            });
        }

        if (Schema::hasColumn('content_items', 'external_key')) {
            Schema::table('content_items', function (Blueprint $table) {
                $table->dropColumn('external_key');
            });
        }

        if (Schema::hasColumn('facilities', 'version')) {
            Schema::table('facilities', function (Blueprint $table) {
                $table->dropColumn('version');
            });
        }

        if (Schema::hasColumn('facilities', 'external_key')) {
            Schema::table('facilities', function (Blueprint $table) {
                $table->dropColumn('external_key');
            });
        }
    }
};
