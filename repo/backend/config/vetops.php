<?php

return [
    'timezone' => env('VETOPS_API_TIMEZONE', 'UTC'),

    'currency' => [
        'code' => env('VETOPS_CURRENCY_CODE', 'USD'),
        'amount_format' => 'integer_cents',
    ],

    'auth' => [
        'inactivity_timeout_minutes' => (int) env('VETOPS_AUTH_INACTIVITY_TIMEOUT_MINUTES', 15),
        'login_max_attempts' => (int) env('VETOPS_AUTH_LOGIN_MAX_ATTEMPTS', 10),
        'login_decay_seconds' => (int) env('VETOPS_AUTH_LOGIN_DECAY_SECONDS', 600),
        // Empty env becomes (int) '' === 0, which would require CAPTCHA on every login; treat as default.
        'captcha_after_failures' => (function (): int {
            $raw = env('VETOPS_AUTH_CAPTCHA_AFTER_FAILURES');
            if ($raw === null || $raw === '') {
                return 5;
            }
            $n = (int) $raw;

            return $n > 0 ? $n : 5;
        })(),
        // Static bypass token removed. Real challenge-based CAPTCHA is now used.
        // Testing-only bypass is handled in CaptchaVerifier::isTestingBypass().
        'captcha_challenge_ttl_minutes' => (int) env('VETOPS_AUTH_CAPTCHA_TTL_MINUTES', 5),
    ],

    'audit' => [
        'retention_years' => (int) env('VETOPS_AUDIT_RETENTION_YEARS', 7),
    ],
];
