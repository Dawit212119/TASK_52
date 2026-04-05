<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_captcha_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('challenge_id', 64)->unique();
            $table->string('username');
            $table->string('workstation_id');
            $table->string('answer_hash', 64);
            $table->string('prompt_type', 32)->default('math');
            $table->text('prompt_content');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedInteger('fail_count_context')->default(0);
            $table->timestamps();
            $table->index(['username', 'workstation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_captcha_challenges');
    }
};
