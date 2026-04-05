<?php

namespace Tests\Feature\Domain;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentReviewsAuditApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_content_workflow_review_moderation_and_q15_partition_archive(): void
    {
        Storage::fake('local');

        $editorToken = $this->login('content.editor');
        $approverToken = $this->login('content.approver');

        $content = $this->withHeader('Authorization', 'Bearer '.$editorToken)
            ->postJson('/api/v1/content/items', [
                'content_type' => 'announcement',
                'title' => 'Shift Update',
                'body' => 'Inventory shelf move.',
                'role_codes' => ['inventory_clerk'],
            ])
            ->assertCreated();

        $contentId = (int) $content->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$editorToken)
            ->postJson('/api/v1/content/items/'.$contentId.'/submit-approval')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$approverToken)
            ->postJson('/api/v1/content/items/'.$contentId.'/approve')
            ->assertOk();

        $managerToken = $this->login('clinic.manager');
        $reviewImage = UploadedFile::fake()->image('image1.jpg');
        $review = $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->post('/api/v1/reviews', [
                'visit_order_id' => '90001',
                'rating' => 1,
                'text' => 'Unhappy service',
                'images' => [$reviewImage],
            ])
            ->assertCreated();

        $reviewId = (int) $review->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->postJson('/api/v1/reviews/'.$reviewId.'/appeal', [
                'reason_category' => 'abusive_language',
            ])
            ->assertCreated();

        DB::table('audit_event_partitions')->insert([
            'partition_key' => '2024_01_facility_1',
            'month_utc' => '2024-01',
            'facility_id' => 1,
            'storage_tier' => 'warm',
            'row_count' => 100,
            'bytes_size' => 1024,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $adminToken = $this->login('system.admin');
        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/v1/audit/archive', [
                'before_month' => '2024-09',
                'compress' => true,
            ])
            ->assertOk();
    }

    public function test_content_read_endpoints_enforce_rbac(): void
    {
        $editorToken = $this->login('content.editor');
        $approverToken = $this->login('content.approver');
        $techToken = $this->login('technician.doctor');

        $this->withHeader('Authorization', 'Bearer '.$editorToken)
            ->getJson('/api/v1/content/items')
            ->assertOk();

        $content = $this->withHeader('Authorization', 'Bearer '.$editorToken)
            ->postJson('/api/v1/content/items', [
                'content_type' => 'announcement',
                'title' => 'Test Read Item',
                'body' => 'Body text.',
            ])
            ->assertCreated();

        $contentId = (int) $content->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$approverToken)
            ->getJson('/api/v1/content/items/'.$contentId)
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$techToken)
            ->getJson('/api/v1/content/items')
            ->assertForbidden();
    }

    public function test_content_access_is_scoped_to_user_facilities(): void
    {
        $adminToken = $this->login('system.admin');
        $editorToken = $this->login('content.editor');

        $facilityTwo = DB::table('facilities')->insertGetId([
            'name' => 'Secondary Facility',
            'code' => 'HQ2',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $content = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/v1/content/items', [
                'content_type' => 'announcement',
                'title' => 'Facility Two Internal Notice',
                'body' => 'Only facility 2 should read this.',
                'facility_ids' => [$facilityTwo],
            ])
            ->assertCreated();

        $contentId = (int) $content->json('data.id');

        $listResponse = $this->withHeader('Authorization', 'Bearer '.$editorToken)
            ->getJson('/api/v1/content/items')
            ->assertOk();

        $this->assertFalse(
            collect($listResponse->json('data'))->contains(fn (array $row): bool => (int) ($row['id'] ?? 0) === $contentId)
        );

        $this->withHeader('Authorization', 'Bearer '.$editorToken)
            ->getJson('/api/v1/content/items/'.$contentId)
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$editorToken)
            ->patchJson('/api/v1/content/items/'.$contentId, [
                'title' => 'Should be denied',
            ])
            ->assertForbidden();
    }

    public function test_public_review_token_flow_stores_uploaded_media_and_masks_phone(): void
    {
        Storage::fake('local');

        $managerToken = $this->login('clinic.manager');
        $issued = $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->postJson('/api/v1/visit-tokens', [
                'visit_order_id' => 'VISIT-PUBLIC-77',
            ])
            ->assertCreated();

        $token = (string) $issued->json('token');

        $image = UploadedFile::fake()->image('owner-upload.jpg');
        $this->post('/api/v1/reviews/public', [
            'visit_order_id' => 'VISIT-PUBLIC-77',
            'token' => $token,
            'rating' => 4,
            'text' => 'Friendly and professional.',
            'owner_phone' => '5551231234',
            'images' => [$image],
        ])->assertCreated();

        $reviewId = (int) DB::table('reviews')->where('visit_order_id', 'VISIT-PUBLIC-77')->value('id');
        $this->assertDatabaseHas('review_media', ['review_id' => $reviewId]);

        $managerList = $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->getJson('/api/v1/reviews')
            ->assertOk();

        $managerPhone = collect($managerList->json('data'))->firstWhere('id', $reviewId)['owner_phone'] ?? null;
        $this->assertSame('(555) ***-1234', $managerPhone);

        $adminToken = $this->login('system.admin');
        $adminList = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/v1/reviews')
            ->assertOk();

        $adminPhone = collect($adminList->json('data'))->firstWhere('id', $reviewId)['owner_phone'] ?? null;
        $this->assertSame('5551231234', $adminPhone);
    }

    // --- Issue A: visit_order_id type consistency tests ---

    public function test_create_review_with_numeric_string_id(): void
    {
        Storage::fake('local');
        $token = $this->login('clinic.manager');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/reviews', [
                'visit_order_id' => '90001',
                'rating' => 4,
                'text' => 'Great service',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('reviews', ['visit_order_id' => '90001']);
    }

    public function test_create_review_with_alphanumeric_id(): void
    {
        Storage::fake('local');
        $token = $this->login('clinic.manager');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/reviews', [
                'visit_order_id' => 'VISIT-PUBLIC-77',
                'rating' => 5,
                'text' => 'Excellent',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('reviews', ['visit_order_id' => 'VISIT-PUBLIC-77']);
    }

    public function test_duplicate_visit_order_id_returns_409(): void
    {
        Storage::fake('local');
        $token = $this->login('clinic.manager');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/reviews', [
                'visit_order_id' => 'DUP-TEST-01',
                'rating' => 3,
            ])
            ->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/reviews', [
                'visit_order_id' => 'DUP-TEST-01',
                'rating' => 4,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_REVIEW');
    }

    // --- Issue E: Null-facility audit log access leak tests ---

    public function test_non_admin_cannot_access_null_facility_audit_log(): void
    {
        $managerToken = $this->login('clinic.manager');
        $adminToken = $this->login('system.admin');

        // Create an audit event with null facility_id (system-wide)
        $eventId = DB::table('audit_events')->insertGetId([
            'event_type' => 'system',
            'action' => 'config_change',
            'status' => 'success',
            'facility_id' => null,
            'method' => 'POST',
            'path' => '/api/v1/system/config',
            'ip_address' => '127.0.0.1',
            'created_at' => now()->utc(),
        ]);

        // Non-admin (clinic.manager) should be denied
        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->getJson('/api/v1/audit/logs/'.$eventId)
            ->assertForbidden();

        // Admin should have access
        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/v1/audit/logs/'.$eventId)
            ->assertOk();
    }

    public function test_non_admin_can_access_own_facility_audit_log(): void
    {
        $managerToken = $this->login('clinic.manager');

        $facilityId = (int) DB::table('facilities')->value('id');

        $eventId = DB::table('audit_events')->insertGetId([
            'event_type' => 'inventory',
            'action' => 'receipt',
            'status' => 'success',
            'facility_id' => $facilityId,
            'method' => 'POST',
            'path' => '/api/v1/inventory/receipts',
            'ip_address' => '127.0.0.1',
            'created_at' => now()->utc(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->getJson('/api/v1/audit/logs/'.$eventId)
            ->assertOk();
    }

    private function login(string $username): string
    {
        $res = $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => $username,
                'password' => 'VetOpsSecure123',
            ])
            ->assertOk();

        return (string) $res->json('token');
    }
}
