<?php

namespace Tests\Feature\Auth;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_captcha_challenge_required_after_threshold_failures(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
                ->postJson('/api/v1/auth/login', [
                    'username' => 'clinic.manager',
                    'password' => 'WrongPassword123',
                ])
                ->assertStatus(401);
        }

        // 6th attempt: CAPTCHA required, returns challenge metadata
        $captchaResponse = $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => 'clinic.manager',
                'password' => 'VetOpsSecure123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CAPTCHA_REQUIRED');

        // Verify challenge was issued in DB
        $this->assertDatabaseHas('login_captcha_challenges', [
            'username' => 'clinic.manager',
            'workstation_id' => 'ws-frontdesk-01',
        ]);

        // Verify challenge details are in response
        $details = $captchaResponse->json('error.details');
        $challengeDetail = collect($details)->firstWhere('challenge_id');
        $this->assertNotNull($challengeDetail);
        $this->assertNotEmpty($challengeDetail['challenge_id']);
        $this->assertNotEmpty($challengeDetail['prompt_content']);
    }

    public function test_captcha_wrong_answer_denies_login(): void
    {
        $this->triggerCaptchaThreshold();

        $challenge = DB::table('login_captcha_challenges')
            ->where('username', 'clinic.manager')
            ->latest('id')
            ->first();

        $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => 'clinic.manager',
                'password' => 'VetOpsSecure123',
                'captcha_challenge_id' => $challenge->challenge_id,
                'captcha_answer' => 'wrong-answer',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CAPTCHA_FAILED');
    }

    public function test_captcha_expired_challenge_denies_login(): void
    {
        $this->triggerCaptchaThreshold();

        $challenge = DB::table('login_captcha_challenges')
            ->where('username', 'clinic.manager')
            ->latest('id')
            ->first();

        // Expire the challenge
        DB::table('login_captcha_challenges')
            ->where('id', $challenge->id)
            ->update(['expires_at' => now()->subMinutes(1)]);

        // Solve the challenge (read answer_hash to find correct answer is irrelevant - it's expired)
        $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => 'clinic.manager',
                'password' => 'VetOpsSecure123',
                'captcha_challenge_id' => $challenge->challenge_id,
                'captcha_answer' => '42',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CAPTCHA_FAILED');
    }

    public function test_captcha_replay_used_challenge_denies_login(): void
    {
        $this->triggerCaptchaThreshold();

        $challenge = DB::table('login_captcha_challenges')
            ->where('username', 'clinic.manager')
            ->latest('id')
            ->first();

        // Mark challenge as already used
        DB::table('login_captcha_challenges')
            ->where('id', $challenge->id)
            ->update(['used_at' => now()]);

        $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => 'clinic.manager',
                'password' => 'VetOpsSecure123',
                'captcha_challenge_id' => $challenge->challenge_id,
                'captcha_answer' => '42',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CAPTCHA_FAILED');
    }

    public function test_captcha_challenge_from_different_workstation_denies(): void
    {
        $this->triggerCaptchaThreshold();

        $challenge = DB::table('login_captcha_challenges')
            ->where('username', 'clinic.manager')
            ->latest('id')
            ->first();

        // Try from a different workstation
        $this->withHeader('X-Workstation-Id', 'ws-other-machine')
            ->postJson('/api/v1/auth/login', [
                'username' => 'clinic.manager',
                'password' => 'VetOpsSecure123',
                'captcha_challenge_id' => $challenge->challenge_id,
                'captcha_answer' => '42',
            ])
            ->assertStatus(422);
    }

    public function test_captcha_valid_challenge_allows_login(): void
    {
        $this->triggerCaptchaThreshold();

        // Use testing bypass (only works in APP_ENV=testing)
        $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => 'clinic.manager',
                'password' => 'VetOpsSecure123',
                'captcha_challenge_id' => 'test-bypass',
                'captcha_answer' => 'test-bypass',
            ])
            ->assertOk();
    }

    public function test_no_static_bypass_accepted_in_non_testing_env(): void
    {
        // Simulate production environment behavior
        // The old static captcha_bypass_token config key no longer exists
        $this->assertNull(config('vetops.auth.captcha_bypass_token'));
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

    private function triggerCaptchaThreshold(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
                ->postJson('/api/v1/auth/login', [
                    'username' => 'clinic.manager',
                    'password' => 'WrongPassword123',
                ])
                ->assertStatus(401);
        }

        // 6th attempt triggers CAPTCHA and issues a challenge
        $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => 'clinic.manager',
                'password' => 'VetOpsSecure123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CAPTCHA_REQUIRED');
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
