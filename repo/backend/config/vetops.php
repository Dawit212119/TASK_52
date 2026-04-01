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
        'captcha_bypass_token' => env('VETOPS_AUTH_CAPTCHA_BYPASS_TOKEN'),
    ],
];
