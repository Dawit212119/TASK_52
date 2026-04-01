<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_review_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('visit_order_id');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index('visit_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_review_tokens');
    }
};
