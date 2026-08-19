<?php

declare(strict_types=1);

return [
    // log | smtp | null
    'mailer' => env('MAIL_MAILER', 'log'),

    'smtp' => [
        'host' => env('MAIL_HOST', 'smtps.aruba.it'),
        'port' => (int) env('MAIL_PORT', 465),
        'encryption' => env('MAIL_ENCRYPTION', 'ssl'),
        'username' => env('MAIL_USERNAME', ''),
        'password' => env('MAIL_PASSWORD', ''),
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@baraondafiorentina.it'),
        'name' => env('MAIL_FROM_NAME', 'Baraonda Fiorentina'),
    ],

    // Destinatari di default: il pannello Impostazioni puo sovrascriverli.
    'to' => [
        'orders' => env('MAIL_ORDERS_TO', 'merchandising@baraondafiorentina.it'),
        'contact' => env('MAIL_CONTACT_TO', 'info@baraondafiorentina.it'),
    ],

    // Cartella dei messaggi .eml prodotti dal mailer di sviluppo.
    'log_directory' => 'logs/mail',
];
