<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_inventory_clerk_is_denied_admin_only_endpoint(): void
    {
        $token = $this->loginAndGetToken('inventory.clerk');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/audit/archive', ['before_month' => '2024-01'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'authorization',
            'action' => 'permission_denied',
            'status' => 'denied',
        ]);
    }

    public function test_clinic_manager_is_allowed_manager_scoped_endpoint(): void
    {
        $token = $this->loginAndGetToken('clinic.manager');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/analytics/reviews/summary')
            ->assertOk();
    }

    private function loginAndGetToken(string $username): string
    {
        $response = $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => $username,
                'password' => 'VetOpsSecure123',
            ])
            ->assertOk();

        return (string) $response->json('token');
    }
}
