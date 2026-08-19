<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Baraonda Fiorentina'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => rtrim((string) env('APP_URL', 'http://localhost:8080'), '/'),
    'timezone' => env('APP_TIMEZONE', 'Europe/Rome'),
    'locale' => env('APP_LOCALE', 'it'),
    'key' => env('APP_KEY', ''),

    'maintenance' => (bool) env('APP_MAINTENANCE', false),
    'force_https' => (bool) env('FORCE_HTTPS', false),

    'version' => '1.0.0',
];
