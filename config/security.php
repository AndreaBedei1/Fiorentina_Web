<?php

declare(strict_types=1);

return [
    // Costo bcrypt usato solo se Argon2id non e disponibile sul runtime.
    'bcrypt_cost' => (int) env('BCRYPT_COST', 12),

    'argon' => [
        'memory_cost' => (int) env('ARGON_MEMORY_COST', 65536),
        'time_cost' => (int) env('ARGON_TIME_COST', 4),
        'threads' => (int) env('ARGON_THREADS', 2),
    ],

    'password' => [
        'min_length' => 8,
        // Durata dei link di reset e di invito, in minuti.
        'reset_token_ttl' => 60,
        'invite_token_ttl' => 60 * 24 * 7,
    ],

    'rate_limits' => [
        'login' => [
            'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
            'decay_minutes' => (int) env('LOGIN_DECAY_MINUTES', 15),
        ],
        'contact' => [
            'max_attempts' => (int) env('CONTACT_MAX_ATTEMPTS', 5),
            'decay_minutes' => (int) env('CONTACT_DECAY_MINUTES', 60),
        ],
        'order' => [
            'max_attempts' => (int) env('ORDER_MAX_ATTEMPTS', 10),
            'decay_minutes' => (int) env('ORDER_DECAY_MINUTES', 60),
        ],
        'password_reset' => [
            'max_attempts' => 5,
            'decay_minutes' => 60,
        ],
    ],

    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),
    ],

    'cron_token' => env('CRON_TOKEN', ''),
];
