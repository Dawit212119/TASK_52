<?php

namespace Tests\Feature\Domain;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportDedupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_q9_conflict_detection_and_resolution(): void
    {
        $adminToken = $this->login('system.admin');

        DB::table('providers')->insert([
            'facility_id' => 1,
            'name' => 'Dr A',
            'external_key' => 'provider.dr.a',
            'version' => 3,
            'status' => 'active',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/v1/imports/providers/validate', [
                'rows' => [[
                    'external_key' => 'provider.dr.a',
                    'name' => 'Dr A Updated',
                    'source_version' => 2,
                    'facility_id' => 1,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.conflicts', 1);

        $commit = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/v1/imports/providers/commit', [
                'continue_on_conflict' => true,
                'rows' => [[
                    'external_key' => 'provider.dr.a',
                    'name' => 'Dr A Updated',
                    'source_version' => 2,
                    'facility_id' => 1,
                ]],
            ])
            ->assertCreated();

        $importId = (string) $commit->json('import_id');
        $conflictId = (int) DB::table('import_conflicts')->value('id');

        $managerToken = $this->login('clinic.manager');
        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->postJson('/api/v1/imports/conflicts/'.$conflictId.'/resolve', [
                'action' => 'overwrite_with_import',
            ])
            ->assertOk();

        $this->assertDatabaseHas('providers', [
            'external_key' => 'provider.dr.a',
            'name' => 'Dr A Updated',
            'version' => 4,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/v1/imports/'.$importId)
            ->assertOk();
    }

    public function test_facilities_import_supports_idempotent_upsert_and_url_normalization(): void
    {
        $adminToken = $this->login('system.admin');

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/v1/imports/facilities/commit', [
                'rows' => [[
                    'external_key' => 'facility.remote.01',
                    'name' => 'Remote Clinic',
                    'code' => 'REMOTE-01',
                    'source_version' => 1,
                    'reference_url' => 'HTTPS://Example.org/Path/?utm_source=abc&z=2&a=1',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('summary.inserted', 1);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/v1/imports/facilities/commit', [
                'rows' => [[
                    'external_key' => 'facility.remote.01',
                    'name' => 'Remote Clinic Updated',
                    'code' => 'REMOTE-01',
                    'source_version' => 1,
                    'reference_url' => 'HTTPS://Example.org/Path/?utm_source=abc&z=2&a=1',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('summary.updated', 1)
            ->assertJsonPath('summary.inserted', 0);

        $facility = DB::table('facilities')->where('external_key', 'facility.remote.01')->first();
        $this->assertNotNull($facility);
        $this->assertSame('Remote Clinic Updated', (string) $facility->name);
        $this->assertSame('https://example.org/Path?a=1&z=2', (string) $facility->reference_url);
        $this->assertSame(2, (int) $facility->version);
    }

    // --- Issue C: Dedup merge with provenance tests ---

    public function test_dedup_merge_requires_payload(): void
    {
        $managerToken = $this->login('clinic.manager');

        $groupId = DB::table('dedup_candidate_groups')->insertGetId([
            'entity' => 'providers',
            'method' => 'key-field-match',
            'status' => 'open',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        // Missing required fields
        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->postJson('/api/v1/dedup/merge/'.$groupId, [])
            ->assertStatus(422);
    }

    public function test_dedup_merge_forbidden_for_non_manager(): void
    {
        $clerkToken = $this->login('inventory.clerk');

        $groupId = DB::table('dedup_candidate_groups')->insertGetId([
            'entity' => 'providers',
            'method' => 'key-field-match',
            'status' => 'open',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$clerkToken)
            ->postJson('/api/v1/dedup/merge/'.$groupId, [
                'canonical_record_id' => 1,
                'merge_record_ids' => [2],
                'reason' => 'Duplicate',
            ])
            ->assertForbidden();
    }

    public function test_dedup_merge_performs_actual_merge_with_provenance(): void
    {
        $managerToken = $this->login('clinic.manager');
        $facilityId = (int) DB::table('facilities')->value('id');

        // Create two duplicate providers
        $providerA = DB::table('providers')->insertGetId([
            'facility_id' => $facilityId,
            'name' => 'Dr Smith',
            'external_key' => 'prov.smith.a',
            'version' => 1,
            'status' => 'active',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $providerB = DB::table('providers')->insertGetId([
            'facility_id' => $facilityId,
            'name' => 'Dr. Smith',
            'external_key' => 'prov.smith.b',
            'version' => 1,
            'status' => 'active',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $groupId = DB::table('dedup_candidate_groups')->insertGetId([
            'entity' => 'providers',
            'method' => 'key-field-match',
            'status' => 'open',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        DB::table('dedup_candidates')->insert([
            ['candidate_group_id' => $groupId, 'record_id' => $providerA, 'score' => 1.0, 'created_at' => now(), 'updated_at' => now()],
            ['candidate_group_id' => $groupId, 'record_id' => $providerB, 'score' => 1.0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->postJson('/api/v1/dedup/merge/'.$groupId, [
                'canonical_record_id' => $providerA,
                'merge_record_ids' => [$providerB],
                'field_resolution' => ['name' => 'Dr. J. Smith'],
                'reason' => 'Confirmed duplicate after manual review',
            ])
            ->assertOk();

        // Verify canonical record was updated
        $this->assertDatabaseHas('providers', [
            'id' => $providerA,
            'name' => 'Dr. J. Smith',
        ]);

        // Verify merged record was marked as merged
        $this->assertDatabaseHas('providers', [
            'id' => $providerB,
            'status' => 'merged',
        ]);

        // Verify provenance records were created
        $this->assertDatabaseHas('dedup_merge_events', [
            'candidate_group_id' => $groupId,
            'canonical_record_id' => $providerA,
            'reason' => 'Confirmed duplicate after manual review',
        ]);

        $mergeEventId = (int) DB::table('dedup_merge_events')->value('id');
        $items = DB::table('dedup_merge_event_items')->where('merge_event_id', $mergeEventId)->get();
        $this->assertCount(2, $items); // canonical + merged record

        // Verify group status is merged
        $this->assertDatabaseHas('dedup_candidate_groups', [
            'id' => $groupId,
            'status' => 'merged',
        ]);
    }

    public function test_repeated_merge_of_closed_group_returns_409(): void
    {
        $managerToken = $this->login('clinic.manager');
        $facilityId = (int) DB::table('facilities')->value('id');

        $providerA = DB::table('providers')->insertGetId([
            'facility_id' => $facilityId, 'name' => 'P1', 'external_key' => 'p1',
            'version' => 1, 'status' => 'active',
            'created_at' => now()->utc(), 'updated_at' => now()->utc(),
        ]);
        $providerB = DB::table('providers')->insertGetId([
            'facility_id' => $facilityId, 'name' => 'P2', 'external_key' => 'p2',
            'version' => 1, 'status' => 'active',
            'created_at' => now()->utc(), 'updated_at' => now()->utc(),
        ]);

        $groupId = DB::table('dedup_candidate_groups')->insertGetId([
            'entity' => 'providers', 'method' => 'key-field-match',
            'status' => 'merged',
            'created_at' => now()->utc(), 'updated_at' => now()->utc(),
        ]);

        DB::table('dedup_candidates')->insert([
            ['candidate_group_id' => $groupId, 'record_id' => $providerA, 'score' => 1.0, 'created_at' => now(), 'updated_at' => now()],
            ['candidate_group_id' => $groupId, 'record_id' => $providerB, 'score' => 1.0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->postJson('/api/v1/dedup/merge/'.$groupId, [
                'canonical_record_id' => $providerA,
                'merge_record_ids' => [$providerB],
                'reason' => 'retry',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ALREADY_MERGED');
    }

    // --- Issue D: Object-level authorization for import report ---

    public function test_import_report_out_of_scope_returns_403(): void
    {
        $adminToken = $this->login('system.admin');

        // Create a facility the clerk has no access to
        $otherFacilityId = DB::table('facilities')->insertGetId([
            'name' => 'Other Clinic', 'code' => 'OTH-01',
            'created_at' => now()->utc(), 'updated_at' => now()->utc(),
        ]);

        // Admin commits import scoped to the other facility
        $commit = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/v1/imports/providers/commit', [
                'rows' => [[
                    'external_key' => 'prov.other.01',
                    'name' => 'Other Provider',
                    'facility_id' => $otherFacilityId,
                ]],
            ])
            ->assertCreated();

        $importId = (string) $commit->json('import_id');

        // Clerk (inventory.clerk has imports.read? No - but let's use clinic.manager who has imports.read)
        $managerToken = $this->login('clinic.manager');

        // Manager is NOT in other facility -> should get 403
        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->getJson('/api/v1/imports/'.$importId)
            ->assertForbidden();
    }

    public function test_import_report_in_scope_returns_200(): void
    {
        $adminToken = $this->login('system.admin');
        $facilityId = (int) DB::table('facilities')->value('id');

        $commit = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/v1/imports/providers/commit', [
                'rows' => [[
                    'external_key' => 'prov.inscope.01',
                    'name' => 'In-scope Provider',
                    'facility_id' => $facilityId,
                ]],
            ])
            ->assertCreated();

        $importId = (string) $commit->json('import_id');

        $managerToken = $this->login('clinic.manager');

        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->getJson('/api/v1/imports/'.$importId)
            ->assertOk();
    }

    public function test_import_report_admin_always_has_access(): void
    {
        $adminToken = $this->login('system.admin');

        $otherFacilityId = DB::table('facilities')->insertGetId([
            'name' => 'Admin Test Clinic', 'code' => 'ADM-01',
            'created_at' => now()->utc(), 'updated_at' => now()->utc(),
        ]);

        $commit = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/v1/imports/providers/commit', [
                'rows' => [[
                    'external_key' => 'prov.admin.01',
                    'name' => 'Admin Test Provider',
                    'facility_id' => $otherFacilityId,
                ]],
            ])
            ->assertCreated();

        $importId = (string) $commit->json('import_id');

        // Admin can always access
        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/v1/imports/'.$importId)
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
