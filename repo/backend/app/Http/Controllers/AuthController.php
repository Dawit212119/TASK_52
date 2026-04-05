<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\ApiToken;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\ApiTokenManager;
use App\Support\AuditLogger;
use App\Support\AuthRateLimiter;
use App\Support\CaptchaVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthRateLimiter $rateLimiter,
        private readonly CaptchaVerifier $captchaVerifier,
        private readonly ApiTokenManager $tokenManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $workstationId = trim((string) $request->header('X-Workstation-Id', 'unknown-workstation'));
        $username = mb_strtolower((string) $request->string('username'));

        if ($this->rateLimiter->tooManyAttempts($workstationId, $username)) {
            return response()->json(
                ApiResponse::error(
                    code: 'RATE_LIMITED',
                    message: 'Too many login attempts. Please try again later.',
                    requestId: ApiResponse::requestId($request),
                    details: [[
                        'field' => 'username',
                        'rule' => 'throttle',
                        'message' => sprintf('Retry after %d seconds.', $this->rateLimiter->availableIn($workstationId, $username)),
                    ]],
                ),
                429,
            );
        }

        $failedAttempts = $this->rateLimiter->failedAttempts($workstationId, $username);
        $captchaThreshold = (int) config('vetops.auth.captcha_after_failures', 5);
        $captchaRequired = $failedAttempts >= $captchaThreshold;

        $challengeId = (string) $request->input('captcha_challenge_id', '');
        $captchaAnswer = (string) $request->input('captcha_answer', '');

        if ($captchaRequired) {
            if ($challengeId === '' || $captchaAnswer === '') {
                $challenge = $this->captchaVerifier->issueChallenge($workstationId, $username, $failedAttempts);

                return response()->json(
                    ApiResponse::error(
                        code: 'CAPTCHA_REQUIRED',
                        message: 'CAPTCHA verification is required.',
                        requestId: ApiResponse::requestId($request),
                        details: [
                            [
                                'field' => 'captcha_challenge_id',
                                'rule' => 'required_after_failures',
                                'message' => 'Submit a valid CAPTCHA response after failed attempts.',
                            ],
                            [
                                'challenge_id' => $challenge['challenge_id'],
                                'prompt_type' => $challenge['prompt_type'],
                                'prompt_content' => $challenge['prompt_content'],
                            ],
                        ],
                    ),
                    422,
                );
            }

            if (! $this->captchaVerifier->verify($challengeId, $captchaAnswer, $workstationId, $username)) {
                $this->rateLimiter->hit($workstationId, $username);
                $this->auditLogger->log($request, 'auth', 'login', 'failure', null, [
                    'username' => $username,
                    'reason' => 'captcha_failed',
                ]);

                return response()->json(
                    ApiResponse::error(
                        code: 'CAPTCHA_FAILED',
                        message: 'CAPTCHA verification failed.',
                        requestId: ApiResponse::requestId($request),
                    ),
                    422,
                );
            }
        } elseif ($challengeId !== '' && $captchaAnswer !== '') {
            // Reject a challenge bound to another workstation even when this WS has not hit the failure threshold.
            if (! $this->captchaVerifier->verify($challengeId, $captchaAnswer, $workstationId, $username)) {
                $this->rateLimiter->hit($workstationId, $username);
                $this->auditLogger->log($request, 'auth', 'login', 'failure', null, [
                    'username' => $username,
                    'reason' => 'captcha_failed',
                ]);

                return response()->json(
                    ApiResponse::error(
                        code: 'CAPTCHA_FAILED',
                        message: 'CAPTCHA verification failed.',
                        requestId: ApiResponse::requestId($request),
                    ),
                    422,
                );
            }
        }

        $user = User::query()
            ->whereRaw('LOWER(username) = ?', [$username])
            ->first();

        if ($user === null || ! $user->is_active || ! Hash::check((string) $request->input('password'), $user->password)) {
            $this->rateLimiter->hit($workstationId, $username);
            $this->auditLogger->log($request, 'auth', 'login', 'failure', $user, [
                'username' => $username,
                'captcha_required' => $captchaRequired,
            ]);

            return response()->json(
                ApiResponse::error(
                    code: 'INVALID_CREDENTIALS',
                    message: 'Invalid username or password.',
                    requestId: ApiResponse::requestId($request),
                ),
                401,
            );
        }

        $this->rateLimiter->clear($workstationId, $username);
        $this->captchaVerifier->invalidateChallenges($workstationId, $username);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.last_activity_at', now()->utc()->timestamp);

        $token = $this->tokenManager->issue($user, 'login');

        $this->auditLogger->log($request, 'auth', 'login', 'success', $user, [
            'username' => $user->username,
            'roles' => $user->loadMissing('roles')->roleCodes(),
        ]);

        return response()->json([
            'token' => $token['token'],
            'expires_in_seconds' => $token['expires_in_seconds'],
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'display_name' => $user->display_name ?? $user->name,
                'roles' => $user->loadMissing('roles')->roleCodes(),
                'facility_ids' => $user->loadMissing('facilities')->facilities->pluck('id')->values()->all(),
            ],
            'security' => [
                'captcha_required' => false,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = Auth::user();

        $bearerToken = $request->bearerToken();
        if ($bearerToken !== null && $bearerToken !== '') {
            $this->tokenManager->revokeCurrent($bearerToken);
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $this->auditLogger->log($request, 'auth', 'logout', 'success', $user);

        return response()->json([], 204);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $user->loadMissing(['roles.permissions', 'permissions', 'facilities']);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'display_name' => $user->display_name ?? $user->name,
                'roles' => $user->roleCodes(),
                'permissions' => $user->permissionCodes(),
                'facility_ids' => $user->facilities->pluck('id')->values()->all(),
            ],
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (! Hash::check((string) $request->input('current_password'), $user->password)) {
            return response()->json(
                ApiResponse::error(
                    code: 'INVALID_CREDENTIALS',
                    message: 'Current password is incorrect.',
                    requestId: ApiResponse::requestId($request),
                    details: [[
                        'field' => 'current_password',
                        'rule' => 'current_password',
                        'message' => 'Current password does not match.',
                    ]],
                ),
                422,
            );
        }

        $user->forceFill([
            'password' => (string) $request->input('new_password'),
            'password_changed_at' => now()->utc(),
        ])->save();

        ApiToken::query()->where('user_id', $user->id)->update([
            'revoked_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $this->auditLogger->log($request, 'auth', 'password_change', 'success', $user);

        return response()->json([
            'message' => 'Password changed successfully. Please login again.',
        ]);
    }
}
