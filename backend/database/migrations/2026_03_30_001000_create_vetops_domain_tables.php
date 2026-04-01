<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_versions', function (Blueprint $table) {
            $table->id();
            $table->string('entity');
            $table->unsignedBigInteger('entity_id');
            $table->unsignedInteger('version');
            $table->json('snapshot_json');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_type');
            $table->timestamp('changed_at_utc');
            $table->index(['entity', 'entity_id', 'version']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->string('name');
            $table->string('external_key')->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('name');
            $table->string('external_key')->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->string('name');
            $table->string('external_key')->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->enum('reservation_strategy', ['reserve_on_order_create', 'deduct_on_order_close'])->default('reserve_on_order_create');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('service_pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->bigInteger('amount_cents');
            $table->char('currency_code', 3)->default('USD');
            $table->timestamp('effective_from_utc');
            $table->timestamp('effective_to_utc')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('facility_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at_local')->nullable();
            $table->time('closes_at_local')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->string('addressable_type');
            $table->unsignedBigInteger('addressable_id');
            $table->string('line_1');
            $table->string('line_2')->nullable();
            $table->string('city');
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country_code', 2)->default('US');
            $table->string('external_key')->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['addressable_type', 'addressable_id']);
        });

        Schema::create('rental_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->string('asset_code')->unique();
            $table->string('qr_code')->nullable()->unique();
            $table->string('barcode')->nullable()->unique();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('photo_url')->nullable();
            $table->json('spec_json')->nullable();
            $table->string('current_location')->nullable();
            $table->bigInteger('replacement_cost_cents');
            $table->bigInteger('daily_rate_cents')->default(0);
            $table->bigInteger('weekly_rate_cents')->default(0);
            $table->bigInteger('deposit_cents')->default(0);
            $table->enum('status', ['available', 'rented', 'maintenance', 'deactivated', 'overdue'])->default('available');
            $table->timestamps();
        });

        Schema::create('rental_checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('rental_assets')->cascadeOnDelete();
            $table->string('renter_type');
            $table->unsignedBigInteger('renter_id');
            $table->timestamp('checked_out_at');
            $table->timestamp('expected_return_at');
            $table->timestamp('returned_at')->nullable();
            $table->enum('pricing_mode', ['daily', 'weekly'])->default('daily');
            $table->bigInteger('deposit_cents');
            $table->text('fee_terms')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['asset_id', 'returned_at']);
        });

        Schema::create('asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('rental_assets')->cascadeOnDelete();
            $table->foreignId('from_facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('to_facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->timestamp('requested_effective_at');
            $table->text('reason');
            $table->enum('status', ['requested', 'approved', 'rejected', 'completed'])->default('requested');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decision_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decision_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });

        Schema::create('rental_asset_ownership_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('rental_assets')->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->timestamp('effective_from_utc');
            $table->timestamp('effective_to_utc')->nullable();
            $table->foreignId('transfer_request_id')->nullable()->constrained('asset_transfers')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['asset_id', 'effective_to_utc']);
        });

        Schema::create('storerooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->string('uom')->default('ea');
            $table->decimal('safety_stock_days', 6, 2)->default(14.00);
            $table->string('external_key')->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('stock_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('storeroom_id')->constrained('storerooms')->cascadeOnDelete();
            $table->enum('movement_type', ['inbound', 'outbound', 'transfer_out', 'transfer_in', 'adjustment']);
            $table->decimal('qty', 12, 3);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at_utc');
            $table->index(['item_id', 'storeroom_id', 'created_at_utc']);
        });

        Schema::create('stocktakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('storeroom_id')->constrained('storerooms')->cascadeOnDelete();
            $table->enum('status', ['draft', 'submitted', 'pending_approval', 'approved', 'rejected'])->default('draft');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('stocktake_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stocktake_id')->constrained('stocktakes')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('system_qty', 12, 3);
            $table->decimal('counted_qty', 12, 3);
            $table->decimal('variance_pct', 8, 3);
            $table->text('variance_reason')->nullable();
            $table->boolean('requires_manager_approval')->default(false);
            $table->timestamps();
        });

        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();
        });

        Schema::create('inventory_reservation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained('service_orders')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('storeroom_id')->constrained('storerooms')->cascadeOnDelete();
            $table->enum('event_type', ['reserve', 'plan', 'consume', 'release']);
            $table->decimal('qty', 12, 3);
            $table->enum('strategy', ['reserve_on_order_create', 'deduct_on_order_close']);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at_utc');
            $table->index(['item_id', 'storeroom_id', 'created_at_utc']);
        });

        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->enum('content_type', ['announcement', 'homepage_carousel']);
            $table->string('title');
            $table->longText('body')->nullable();
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'published', 'rejected', 'archived'])->default('draft');
            $table->unsignedInteger('current_version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('content_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('snapshot_json');
            $table->string('status');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at_utc');
            $table->index(['content_item_id', 'version_number']);
        });

        Schema::create('content_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->json('facility_ids')->nullable();
            $table->json('department_ids')->nullable();
            $table->json('role_codes')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('visit_order_id')->unique();
            $table->foreignId('facility_id')->nullable()->constrained('facilities')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->json('tags')->nullable();
            $table->text('text')->nullable();
            $table->enum('visibility_status', ['visible', 'hidden'])->default('visible');
            $table->timestamps();
        });

        Schema::create('review_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->string('filename');
            $table->string('path');
            $table->string('checksum_sha256', 64);
            $table->unsignedBigInteger('bytes_size')->default(0);
            $table->timestamp('created_at_utc');
        });

        Schema::create('review_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('response_text');
            $table->timestamp('created_at_utc');
        });

        Schema::create('review_moderation_policies', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->enum('category', ['abusive_language', 'harassment', 'privacy', 'spam', 'other']);
            $table->text('rule_text');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('review_moderation_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->enum('reason_category', ['abusive_language', 'harassment', 'privacy', 'spam', 'other']);
            $table->string('policy_version');
            $table->enum('status', ['open', 'upheld', 'rejected', 'escalated', 'closed'])->default('open');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decision_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamp('created_at_utc');
            $table->timestamp('decided_at_utc')->nullable();
            $table->timestamps();
        });

        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('entity');
            $table->string('status')->default('validated');
            $table->boolean('continue_on_conflict')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('summary_json')->nullable();
            $table->timestamps();
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->constrained('import_jobs')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('entity');
            $table->string('external_key')->nullable();
            $table->unsignedInteger('source_version')->nullable();
            $table->string('status')->default('validated');
            $table->json('payload_json');
            $table->json('errors_json')->nullable();
            $table->json('result_json')->nullable();
            $table->timestamps();
            $table->index(['import_job_id', 'row_number']);
        });

        Schema::create('import_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->constrained('import_jobs')->cascadeOnDelete();
            $table->string('entity');
            $table->string('external_key');
            $table->unsignedInteger('row_number');
            $table->unsignedInteger('db_version');
            $table->unsignedInteger('source_version')->nullable();
            $table->enum('status', ['open', 'resolved', 'ignored'])->default('open');
            $table->enum('resolution_action', ['overwrite_with_import', 'keep_existing', 'merge_fields'])->nullable();
            $table->json('resolution_payload_json')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at_utc')->nullable();
            $table->timestamps();
        });

        Schema::create('dedup_candidate_groups', function (Blueprint $table) {
            $table->id();
            $table->string('entity');
            $table->string('method');
            $table->string('status')->default('open');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('dedup_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_group_id')->constrained('dedup_candidate_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('record_id');
            $table->decimal('score', 6, 3)->nullable();
            $table->json('fingerprint')->nullable();
            $table->timestamps();
        });

        Schema::table('audit_events', function (Blueprint $table) {
            $table->foreignId('facility_id')->nullable()->after('workstation_id')->constrained('facilities')->nullOnDelete();
            $table->string('partition_key')->nullable()->after('facility_id')->index();
        });

        Schema::create('audit_event_partitions', function (Blueprint $table) {
            $table->id();
            $table->string('partition_key')->unique();
            $table->string('month_utc', 7);
            $table->foreignId('facility_id')->nullable()->constrained('facilities')->nullOnDelete();
            $table->enum('storage_tier', ['hot', 'warm', 'archive'])->default('hot');
            $table->unsignedBigInteger('row_count')->default(0);
            $table->unsignedBigInteger('bytes_size')->default(0);
            $table->timestamp('sealed_at_utc')->nullable();
            $table->timestamp('archived_at_utc')->nullable();
            $table->timestamps();
            $table->index(['month_utc', 'facility_id']);
        });

        Schema::create('analytics_review_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->nullable()->constrained('facilities')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->date('snapshot_date');
            $table->decimal('average_score', 6, 3)->default(0);
            $table->decimal('negative_review_rate', 6, 3)->default(0);
            $table->integer('median_response_time_minutes')->default(0);
            $table->timestamps();
        });

        Schema::create('file_integrity_checks', function (Blueprint $table) {
            $table->id();
            $table->string('file_path');
            $table->string('checksum_sha256', 64);
            $table->enum('status', ['ok', 'mismatch', 'missing'])->default('ok');
            $table->timestamp('checked_at_utc');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_integrity_checks');
        Schema::dropIfExists('analytics_review_snapshots');
        Schema::dropIfExists('audit_event_partitions');
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('facility_id');
            $table->dropColumn('partition_key');
        });
        Schema::dropIfExists('dedup_candidates');
        Schema::dropIfExists('dedup_candidate_groups');
        Schema::dropIfExists('import_conflicts');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_jobs');
        Schema::dropIfExists('review_moderation_cases');
        Schema::dropIfExists('review_moderation_policies');
        Schema::dropIfExists('review_responses');
        Schema::dropIfExists('review_media');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('content_targets');
        Schema::dropIfExists('content_versions');
        Schema::dropIfExists('content_items');
        Schema::dropIfExists('inventory_reservation_events');
        Schema::dropIfExists('service_orders');
        Schema::dropIfExists('stocktake_lines');
        Schema::dropIfExists('stocktakes');
        Schema::dropIfExists('stock_ledger_entries');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('storerooms');
        Schema::dropIfExists('rental_asset_ownership_history');
        Schema::dropIfExists('asset_transfers');
        Schema::dropIfExists('rental_checkouts');
        Schema::dropIfExists('rental_assets');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('facility_hours');
        Schema::dropIfExists('service_pricings');
        Schema::dropIfExists('services');
        Schema::dropIfExists('providers');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('master_versions');
    }
};
