<?php

namespace App\Support;

use App\Models\ApiToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ApiTokenManager
{
    /**
     * @return array{token: string, expires_in_seconds: int}
     */
    public function issue(User $user, string $name = 'auth-login'): array
    {
        $plainToken = Str::random(80);

        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plainToken),
            'last_used_at' => now()->utc(),
            'expires_at' => CarbonImmutable::now()->utc()->addMinutes($this->inactivityTimeoutMinutes()),
        ]);

        return [
            'token' => $plainToken,
            'expires_in_seconds' => $this->inactivityTimeoutMinutes() * 60,
        ];
    }

    public function revokeCurrent(string $plainToken): void
    {
        ApiToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->update([
                'revoked_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);
    }

    public function inactivityTimeoutMinutes(): int
    {
        return (int) config('vetops.auth.inactivity_timeout_minutes', 15);
    }
}
