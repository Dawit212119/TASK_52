<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

class AuthRateLimiter
{
    public function tooManyAttempts(string $workstationId, string $username): bool
    {
        return RateLimiter::tooManyAttempts($this->key($workstationId, $username), $this->maxAttempts());
    }

    public function failedAttempts(string $workstationId, string $username): int
    {
        return RateLimiter::attempts($this->key($workstationId, $username));
    }

    public function hit(string $workstationId, string $username): int
    {
        return RateLimiter::hit($this->key($workstationId, $username), $this->decaySeconds());
    }

    public function clear(string $workstationId, string $username): void
    {
        RateLimiter::clear($this->key($workstationId, $username));
    }

    public function availableIn(string $workstationId, string $username): int
    {
        return RateLimiter::availableIn($this->key($workstationId, $username));
    }

    private function key(string $workstationId, string $username): string
    {
        return sprintf('auth:login:%s:%s', mb_strtolower($workstationId), mb_strtolower($username));
    }

    private function maxAttempts(): int
    {
        return (int) config('vetops.auth.login_max_attempts', 10);
    }

    private function decaySeconds(): int
    {
        return (int) config('vetops.auth.login_decay_seconds', 600);
    }
}
