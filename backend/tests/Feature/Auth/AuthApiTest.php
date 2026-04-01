<?php

namespace Tests\Feature\Auth;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_login_success_and_me_endpoint(): void
    {
        $loginResponse = $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => 'clinic.manager',
                'password' => 'VetOpsSecure123',
            ]);

        $loginResponse
            ->assertOk()
            ->assertJsonStructure([
                'token',
                'expires_in_seconds',
                'user' => ['id', 'username', 'display_name', 'roles', 'facility_ids'],
                'security' => ['captcha_required'],
            ]);

        $token = (string) $loginResponse->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.username', 'clinic.manager');

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'auth',
            'action' => 'login',
            'status' => 'success',
        ]);
    }

    public function test_login_failure_returns_unauthorized_and_audit_event(): void
    {
        $response = $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => 'clinic.manager',
                'password' => 'WrongPassword123',
            ]);

        $response
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'auth',
            'action' => 'login',
            'status' => 'failure',
        ]);
    }

    public function test_captcha_is_required_after_five_failed_attempts(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
                ->postJson('/api/v1/auth/login', [
                    'username' => 'clinic.manager',
                    'password' => 'WrongPassword123',
                ])
                ->assertStatus(401);
        }

        $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => 'clinic.manager',
                'password' => 'VetOpsSecure123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CAPTCHA_REQUIRED');

        $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => 'clinic.manager',
                'password' => 'VetOpsSecure123',
                'captcha_token' => 'local-captcha-ok',
            ])
            ->assertOk();
    }

    public function test_inactivity_timeout_invalidates_token(): void
    {
        config()->set('vetops.auth.inactivity_timeout_minutes', 15);

        $loginResponse = $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => 'clinic.manager',
                'password' => 'VetOpsSecure123',
            ])->assertOk();

        $token = (string) $loginResponse->json('token');
        ApiToken::query()->update([
            'last_used_at' => now()->subMinutes(16),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'SESSION_EXPIRED');
    }

    public function test_logout_revokes_current_token(): void
    {
        $loginResponse = $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => 'clinic.manager',
                'password' => 'VetOpsSecure123',
            ])->assertOk();

        $token = (string) $loginResponse->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertGreaterThan(0, AuditEvent::query()->where('action', 'logout')->count());
    }
}
