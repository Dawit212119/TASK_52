<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dedup_merge_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_group_id')->constrained('dedup_candidate_groups')->cascadeOnDelete();
            $table->string('entity');
            $table->unsignedBigInteger('canonical_record_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('field_resolution_json')->nullable();
            $table->timestamps();
        });

        Schema::create('dedup_merge_event_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merge_event_id')->constrained('dedup_merge_events')->cascadeOnDelete();
            $table->unsignedBigInteger('source_record_id');
            $table->unsignedBigInteger('target_record_id');
            $table->json('before_snapshot_json');
            $table->json('after_snapshot_json')->nullable();
            $table->string('action');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dedup_merge_event_items');
        Schema::dropIfExists('dedup_merge_events');
    }
};
