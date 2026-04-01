<?php

namespace Tests\Feature\Domain;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
