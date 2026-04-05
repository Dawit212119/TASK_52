<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Smoke coverage for every registered /api/v1 route (excluding duplicate deep scenarios covered in Domain/* tests).
 */
class AllApiEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function token(string $username): string
    {
        $res = $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => $username,
                'password' => 'VetOpsSecure123',
            ])
            ->assertOk();

        return (string) $res->json('token');
    }

    private function admin(): string
    {
        return $this->token('system.admin');
    }

    public function test_health_endpoint(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok');
    }

    public function test_auth_csrf_token(): void
    {
        $this->getJson('/api/v1/auth/csrf-token')
            ->assertOk()
            ->assertJsonStructure(['csrf_token', 'request_id']);
    }

    public function test_auth_login_me_and_logout(): void
    {
        $token = $this->admin();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.username', 'system.admin');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();
    }

    public function test_auth_password_change_rejects_wrong_current_password(): void
    {
        $token = $this->admin();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/password/change', [
                'current_password' => 'not-the-password',
                'new_password' => 'NewSecurePass456789',
                'new_password_confirmation' => 'NewSecurePass456789',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_master_data_routes(): void
    {
        $t = $this->admin();

        $this->withApiAuth($t)->getJson('/api/v1/master/facilities')->assertOk();

        $create = $this->withApiAuth($t)->postJson('/api/v1/master/departments', [
            'facility_id' => 1,
            'name' => 'ApiSmoke Dept',
            'external_key' => 'dept.api_smoke',
        ])->assertCreated();

        $id = (int) $create->json('data.id');

        $this->withApiAuth($t)->patchJson('/api/v1/master/departments/'.$id, [
            'name' => 'ApiSmoke Dept 2',
        ])->assertOk();

        $this->withApiAuth($t)->getJson('/api/v1/master/departments/'.$id.'/versions')->assertOk();

        $this->withApiAuth($t)->postJson('/api/v1/master/departments/'.$id.'/revert', [
            'version' => 1,
        ])->assertOk();
    }

    public function test_rentals_routes(): void
    {
        $t = $this->admin();

        $this->withApiAuth($t)->getJson('/api/v1/rentals/assets')->assertOk();

        $asset = $this->withApiAuth($t)->postJson('/api/v1/rentals/assets', [
            'facility_id' => 1,
            'asset_code' => 'API-SMOKE-ASSET',
            'name' => 'Api Smoke',
            'replacement_cost_cents' => 50000,
        ])->assertCreated();

        $assetId = (int) $asset->json('data.id');

        $this->withApiAuth($t)->patchJson('/api/v1/rentals/assets/'.$assetId, [
            'name' => 'Api Smoke Updated',
        ])->assertOk();

        $xfer = $this->withApiAuth($t)->postJson('/api/v1/rentals/assets/'.$assetId.'/transfer', [
            'to_facility_id' => 1,
            'requested_effective_at' => now()->utc()->addDay()->toISOString(),
            'reason' => 'smoke',
        ])->assertCreated();

        $this->withApiAuth($t)->postJson('/api/v1/rentals/transfers/'.$xfer->json('data.id').'/approve')->assertOk();

        $checkout = $this->withApiAuth($t)->postJson('/api/v1/rentals/checkouts', [
            'asset_id' => $assetId,
            'renter_type' => 'department',
            'renter_id' => 1,
            'checked_out_at' => now()->utc()->toISOString(),
            'expected_return_at' => now()->utc()->addDay()->toISOString(),
            'pricing_mode' => 'daily',
            'deposit_cents' => 5000,
        ])->assertCreated();

        $checkoutId = (int) $checkout->json('data.id');

        $this->withApiAuth($t)->getJson('/api/v1/rentals/checkouts/'.$checkoutId)->assertOk();

        $this->withApiAuth($t)->postJson('/api/v1/rentals/checkouts/'.$checkoutId.'/return')->assertNoContent();
    }

    public function test_inventory_routes(): void
    {
        $clerk = $this->token('inventory.clerk');
        $admin = $this->admin();
        $itemId = (int) DB::table('inventory_items')->value('id');
        $storeroomId = (int) DB::table('storerooms')->value('id');

        $this->withApiAuth($clerk)->getJson('/api/v1/inventory/items')->assertOk();

        $this->withApiAuth($clerk)->postJson('/api/v1/inventory/receipts', [
            'item_id' => $itemId,
            'facility_id' => 1,
            'storeroom_id' => $storeroomId,
            'qty' => 5,
        ])->assertCreated();

        $this->withApiAuth($clerk)->postJson('/api/v1/inventory/issues', [
            'item_id' => $itemId,
            'facility_id' => 1,
            'storeroom_id' => $storeroomId,
            'qty' => 1,
        ])->assertCreated();

        $this->withApiAuth($clerk)->postJson('/api/v1/inventory/transfers', [
            'item_id' => $itemId,
            'facility_id' => 1,
            'from_storeroom_id' => $storeroomId,
            'to_storeroom_id' => $storeroomId,
            'qty' => 0.5,
        ])->assertStatus(422);

        $otherRoom = DB::table('storerooms')->insertGetId([
            'code' => 'HQ-AUX',
            'facility_id' => 1,
            'name' => 'Aux',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $this->withApiAuth($clerk)->postJson('/api/v1/inventory/transfers', [
            'item_id' => $itemId,
            'facility_id' => 1,
            'from_storeroom_id' => $storeroomId,
            'to_storeroom_id' => $otherRoom,
            'qty' => 0.5,
        ])->assertCreated();

        $stocktake = $this->withApiAuth($clerk)->postJson('/api/v1/inventory/stocktakes', [
            'facility_id' => 1,
            'storeroom_id' => $storeroomId,
        ])->assertCreated();

        $sid = (int) $stocktake->json('data.id');

        $this->withApiAuth($clerk)->postJson('/api/v1/inventory/stocktakes/'.$sid.'/lines', [
            'lines' => [
                ['item_id' => $itemId, 'counted_qty' => 4, 'variance_reason' => 'test'],
            ],
        ])->assertOk();

        $this->withApiAuth($admin)->postJson('/api/v1/inventory/stocktakes/'.$sid.'/approve-variance', [
            'reason' => 'smoke',
        ])->assertOk();

        $serviceId = (int) DB::table('services')->insertGetId([
            'facility_id' => 1,
            'name' => 'Sm svc',
            'external_key' => 'svc.smoke',
            'version' => 1,
            'reservation_strategy' => 'deduct_on_order_close',
            'status' => 'active',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $this->withApiAuth($clerk)->putJson('/api/v1/inventory/reservation-strategy', [
            'service_id' => $serviceId,
            'strategy' => 'deduct_on_order_close',
        ])->assertOk();

        $this->withApiAuth($clerk)->postJson('/api/v1/inventory/service-orders/7777/reserve', [
            'service_id' => $serviceId,
            'storeroom_id' => $storeroomId,
            'lines' => [['item_id' => $itemId, 'qty' => 1]],
        ])->assertCreated();

        $this->withApiAuth($clerk)->postJson('/api/v1/inventory/service-orders/7777/close')->assertOk();
    }

    public function test_content_and_reviews_routes(): void
    {
        $editor = $this->token('content.editor');
        $approver = $this->token('content.approver');
        $manager = $this->token('clinic.manager');

        $content = $this->withApiAuth($editor)->postJson('/api/v1/content/items', [
            'content_type' => 'announcement',
            'title' => 'API smoke',
            'body' => 'x',
        ])->assertCreated();

        $cid = (int) $content->json('data.id');

        $this->withApiAuth($editor)->patchJson('/api/v1/content/items/'.$cid, [
            'title' => 'API smoke 2',
        ])->assertOk();

        $this->withApiAuth($editor)->postJson('/api/v1/content/items/'.$cid.'/submit-approval')->assertOk();
        $this->withApiAuth($approver)->postJson('/api/v1/content/items/'.$cid.'/approve')->assertOk();
        $this->withApiAuth($approver)->getJson('/api/v1/content/items/'.$cid.'/versions')->assertOk();

        $review = $this->withApiAuth($manager)->postJson('/api/v1/reviews', [
            'visit_order_id' => 'allapi-smoke-visit-001',
            'rating' => 3,
            'text' => 'ok',
        ])->assertCreated();

        $rid = (int) $review->json('data.id');

        $tech = $this->token('technician.doctor');
        $this->withApiAuth($tech)->postJson('/api/v1/reviews/'.$rid.'/responses', [
            'response_text' => 'Thank you for feedback.',
        ])->assertOk();

        $this->withApiAuth($manager)->postJson('/api/v1/reviews/'.$rid.'/appeal', [
            'reason_category' => 'other',
        ])->assertCreated();

        $this->withApiAuth($manager)->postJson('/api/v1/reviews/'.$rid.'/hide')->assertOk();

        $this->withApiAuth($manager)->getJson('/api/v1/reviews')->assertOk();

        $rejectFlow = $this->withApiAuth($editor)->postJson('/api/v1/content/items', [
            'content_type' => 'announcement',
            'title' => 'To reject',
            'body' => 'y',
        ])->assertCreated();
        $rejectId = (int) $rejectFlow->json('data.id');
        $this->withApiAuth($editor)->postJson('/api/v1/content/items/'.$rejectId.'/submit-approval')->assertOk();
        $this->withApiAuth($approver)->postJson('/api/v1/content/items/'.$rejectId.'/reject')->assertOk();

        $this->withApiAuth($approver)->postJson('/api/v1/content/items/'.$cid.'/rollback', [
            'version' => 1,
        ])->assertOk();
    }

    public function test_analytics_routes(): void
    {
        $t = $this->token('clinic.manager');

        $this->withApiAuth($t)->getJson('/api/v1/analytics/reviews/summary')->assertOk();
        $this->withApiAuth($t)->getJson('/api/v1/analytics/inventory/low-stock')->assertOk();
        $this->withApiAuth($t)->getJson('/api/v1/analytics/rentals/overdue')->assertOk();
    }

    public function test_audit_routes(): void
    {
        $t = $this->admin();

        $this->withApiAuth($t)->getJson('/api/v1/audit/logs')->assertOk();
        $this->withApiAuth($t)->getJson('/api/v1/audit/partitions')->assertOk();

        $firstLogId = (int) (DB::table('audit_events')->value('id') ?? 0);
        if ($firstLogId > 0) {
            $this->withApiAuth($t)->getJson('/api/v1/audit/logs/'.$firstLogId)->assertOk();
        }

        $this->withApiAuth($t)->postJson('/api/v1/audit/archive', [
            'before_month' => '2099-01',
        ])->assertOk();

        DB::table('audit_event_partitions')->insertOrIgnore([
            'partition_key' => 'smoke_part',
            'month_utc' => '2025-01',
            'facility_id' => 1,
            'storage_tier' => 'warm',
            'row_count' => 0,
            'bytes_size' => 0,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $this->withApiAuth($t)->postJson('/api/v1/audit/reindex', [
            'partition_key' => 'smoke_part',
        ])->assertOk();
    }

    public function test_imports_exports_dedup_routes(): void
    {
        $t = $this->admin();

        $this->withApiAuth($t)->postJson('/api/v1/imports/departments/validate', [
            'rows' => [[
                'external_key' => 'dept.smoke_new',
                'name' => 'Smoke',
                'facility_id' => 1,
            ]],
        ])->assertOk();

        $commit = $this->withApiAuth($t)->postJson('/api/v1/imports/departments/commit', [
            'continue_on_conflict' => true,
            'rows' => [[
                'external_key' => 'dept.smoke_new',
                'name' => 'Smoke',
                'facility_id' => 1,
            ]],
        ])->assertCreated();

        $importId = (string) $commit->json('import_id');

        $this->withApiAuth($t)->getJson('/api/v1/imports/'.$importId)->assertOk();

        $this->withApiAuth($t)->postJson('/api/v1/imports/'.$importId.'/rollback')->assertOk();

        $this->withApiAuth($t)->getJson('/api/v1/exports/departments')->assertOk();

        $now = now()->utc();
        DB::table('providers')->insert([
            [
                'facility_id' => 1,
                'department_id' => null,
                'name' => 'AllApi Dedup Twin',
                'external_key' => 'allapi.dedup.smoke.a',
                'version' => 1,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'facility_id' => 1,
                'department_id' => null,
                'name' => 'AllApi Dedup Twin',
                'external_key' => 'allapi.dedup.smoke.b',
                'version' => 1,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $scan = $this->withApiAuth($t)->postJson('/api/v1/dedup/scan', ['entity' => 'providers']);
        $scan->assertOk();
        $groupId = (int) $scan->json('data.candidate_group_id');
        $candidateRows = $scan->json('data.candidates');
        $this->assertGreaterThanOrEqual(2, count($candidateRows));
        $recordIds = collect($candidateRows)->pluck('record_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $canonicalId = $recordIds[0];
        $mergeId = $recordIds[1];

        $this->withApiAuth($t)->postJson('/api/v1/dedup/merge/'.$groupId, [
            'canonical_record_id' => $canonicalId,
            'merge_record_ids' => [$mergeId],
            'reason' => 'API smoke merge',
        ])->assertOk();

        DB::table('providers')->insert([
            'facility_id' => 1,
            'name' => 'Dr Conflict',
            'external_key' => 'provider.smoke.conflict',
            'version' => 3,
            'status' => 'active',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $this->withApiAuth($t)->postJson('/api/v1/imports/providers/commit', [
            'continue_on_conflict' => true,
            'rows' => [[
                'external_key' => 'provider.smoke.conflict',
                'name' => 'Dr Conflict Renamed',
                'source_version' => 2,
                'facility_id' => 1,
            ]],
        ])->assertCreated();

        $conflictId = (int) DB::table('import_conflicts')->orderByDesc('id')->value('id');
        $this->withApiAuth($this->token('clinic.manager'))->postJson('/api/v1/imports/conflicts/'.$conflictId.'/resolve', [
            'action' => 'keep_existing',
        ])->assertOk();
    }

    private function withApiAuth(string $token)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Workstation-Id' => 'ws-frontdesk-01',
        ]);
    }
}
