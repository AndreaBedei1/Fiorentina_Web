<?php

declare(strict_types=1);

/**
 * Router per il server integrato di PHP (solo sviluppo).
 *
 *     php -S 127.0.0.1:8080 -t public public/router.php
 *
 * Riproduce il comportamento di mod_rewrite: i file esistenti vengono serviti
 * cosi come sono, tutto il resto finisce nel front controller. In produzione
 * questo file non ha alcun ruolo: se ne occupa Apache.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

// Blocca gli accessi fuori dalla cartella pubblica: il server integrato non
// applica le regole di .htaccess.
if (str_contains($path, '..')) {
    http_response_code(400);

    return true;
}

$file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $path);

if ($path !== '/' && is_file($file)) {
    // Il server integrato non imposta il MIME corretto per alcuni formati.
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    $mimeTypes = [
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'avif' => 'image/avif',
        'webmanifest' => 'application/manifest+json',
    ];

    if (isset($mimeTypes[$extension])) {
        header('Content-Type: ' . $mimeTypes[$extension]);
        readfile($file);

        return true;
    }

    return false;
}

require __DIR__ . '/index.php';

return true;
