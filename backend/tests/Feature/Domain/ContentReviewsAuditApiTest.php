<?php

namespace Tests\Feature\Domain;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $review = $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->postJson('/api/v1/reviews', [
                'visit_order_id' => 90001,
                'rating' => 1,
                'text' => 'Unhappy service',
                'images' => [[
                    'filename' => 'image1.jpg',
                    'path' => 'storage/reviews/image1.jpg',
                    'checksum_sha256' => str_repeat('a', 64),
                    'bytes_size' => 100,
                ]],
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

        // content.editor can list
        $this->withHeader('Authorization', 'Bearer '.$editorToken)
            ->getJson('/api/v1/content/items')
            ->assertOk();

        // Create a content item to test show endpoint
        $content = $this->withHeader('Authorization', 'Bearer '.$editorToken)
            ->postJson('/api/v1/content/items', [
                'content_type' => 'announcement',
                'title' => 'Test Read Item',
                'body' => 'Body text.',
            ])
            ->assertCreated();

        $contentId = (int) $content->json('data.id');

        // content.approver can show by id
        $this->withHeader('Authorization', 'Bearer '.$approverToken)
            ->getJson('/api/v1/content/items/'.$contentId)
            ->assertOk();

        // technician.doctor (no content.read) gets 403
        $this->withHeader('Authorization', 'Bearer '.$techToken)
            ->getJson('/api/v1/content/items')
            ->assertForbidden();
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
