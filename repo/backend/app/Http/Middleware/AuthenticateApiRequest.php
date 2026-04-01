<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiRequest
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if ($bearerToken !== null && $bearerToken !== '') {
            $token = ApiToken::query()
                ->where('token_hash', hash('sha256', $bearerToken))
                ->first();

            if ($token === null || $token->revoked_at !== null || $token->user === null || ! $token->user->is_active) {
                return $this->unauthenticated($request);
            }

            $timeoutMinutes = (int) config('vetops.auth.inactivity_timeout_minutes', 15);
            $lastUsedAt = $token->last_used_at ?? $token->created_at;
            if ($lastUsedAt !== null && $lastUsedAt->lt(now()->utc()->subMinutes($timeoutMinutes))) {
                $token->forceFill(['revoked_at' => now()->utc(), 'updated_at' => now()->utc()])->save();

                $this->auditLogger->log($request, 'auth', 'session_timeout', 'denied', $token->user, [
                    'reason' => 'token_inactive',
                    'timeout_minutes' => $timeoutMinutes,
                ]);

                return response()->json(
                    ApiResponse::error('SESSION_EXPIRED', 'Session timed out due to inactivity.', ApiResponse::requestId($request)),
                    401,
                );
            }

            $token->forceFill([
                'last_used_at' => now()->utc(),
                'expires_at' => now()->utc()->addMinutes($timeoutMinutes),
            ])->save();

            Auth::setUser($token->user);
            $request->attributes->set('api_token_id', $token->id);

            return $next($request);
        }

        if (Auth::guard('web')->check()) {
            $sessionKey = 'auth.last_activity_at';
            $lastActivityAt = (int) $request->session()->get($sessionKey, now()->utc()->timestamp);
            $timeoutSeconds = (int) config('vetops.auth.inactivity_timeout_minutes', 15) * 60;

            if ((now()->utc()->timestamp - $lastActivityAt) > $timeoutSeconds) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json(
                    ApiResponse::error('SESSION_EXPIRED', 'Session timed out due to inactivity.', ApiResponse::requestId($request)),
                    401,
                );
            }

            $request->session()->put($sessionKey, now()->utc()->timestamp);

            return $next($request);
        }

        return $this->unauthenticated($request);
    }

    private function unauthenticated(Request $request): Response
    {
        return response()->json(
            ApiResponse::error('UNAUTHENTICATED', 'Authentication is required.', ApiResponse::requestId($request)),
            401,
        );
    }
}
