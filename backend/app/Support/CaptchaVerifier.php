<?php

namespace App\Support;

class CaptchaVerifier
{
    public function isValid(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals((string) config('vetops.auth.captcha_bypass_token'), $token);
    }
}
