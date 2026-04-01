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
                'action' => 'keep_existing',
            ])
            ->assertOk();

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
