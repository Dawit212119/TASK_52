<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->after('id');
            $table->string('display_name')->nullable()->after('name');
            $table->boolean('is_active')->default(true)->after('remember_token');
            $table->timestamp('password_changed_at')->nullable()->after('password');

            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'display_name', 'is_active', 'password_changed_at']);
        });
    }
};
