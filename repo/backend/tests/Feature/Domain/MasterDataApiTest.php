<?php

namespace Tests\Feature\Domain;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MasterDataApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_master_data_version_and_revert_flow(): void
    {
        $token = $this->login('system.admin');

        $create = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/master/departments', [
                'facility_id' => 1,
                'name' => 'Surgery',
                'external_key' => 'dept.surgery',
            ])
            ->assertCreated();

        $id = (int) $create->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/master/departments/'.$id, [
                'name' => 'Surgery Updated',
            ])
            ->assertOk();

        $versions = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/master/departments/'.$id.'/versions')
            ->assertOk();

        $targetVersion = (int) data_get($versions->json('data'), '1.version', 1);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/master/departments/'.$id.'/revert', [
                'version' => $targetVersion,
            ])
            ->assertOk();
    }

    public function test_master_versions_endpoint_enforces_facility_scope(): void
    {
        $restrictedFacilityId = DB::table('facilities')->insertGetId([
            'name' => 'Remote Clinic',
            'code' => 'RC-01',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $departmentId = DB::table('departments')->insertGetId([
            'facility_id' => $restrictedFacilityId,
            'name' => 'Surgery Remote',
            'external_key' => 'dept.remote.surgery',
            'version' => 1,
            'status' => 'active',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        DB::table('master_versions')->insert([
            'entity' => 'departments',
            'entity_id' => $departmentId,
            'version' => 1,
            'snapshot_json' => json_encode(['id' => $departmentId, 'name' => 'Surgery Remote'], JSON_THROW_ON_ERROR),
            'changed_by_user_id' => null,
            'change_type' => 'create',
            'changed_at_utc' => now()->utc(),
        ]);

        $clerkToken = $this->login('inventory.clerk');

        $this->withHeader('Authorization', 'Bearer '.$clerkToken)
            ->getJson('/api/v1/master/departments/'.$departmentId.'/versions')
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
