<?php

declare(strict_types=1);

return [
    'name' => env('SESSION_NAME', 'baraonda_session'),

    // Minuti di inattivita oltre i quali un amministratore viene disconnesso.
    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    // Obbligatorio a true in produzione: senza, il cookie viaggia anche in chiaro.
    'secure' => (bool) env('SESSION_SECURE', false),

    // Lax protegge dal CSRF cross-site mantenendo funzionanti i link in entrata.
    'same_site' => env('SESSION_SAMESITE', 'Lax'),
];
