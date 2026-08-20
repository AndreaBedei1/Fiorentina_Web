<?php

declare(strict_types=1);

/**
 * Bootstrap condiviso dagli script da riga di comando (migrazioni, seed, cron).
 *
 * Restituisce l'applicazione già avviata. Tenuto separato dal front controller
 * HTTP perché gli script di cron su Aruba vengono lanciati direttamente dalla
 * CLI, senza passare da public/index.php.
 */

use App\Core\Application;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Questo script si esegue solo da riga di comando.\n");
}

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new Application(dirname(__DIR__));

return $app->boot();
