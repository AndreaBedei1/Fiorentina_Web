<?php

declare(strict_types=1);

/**
 * Pulisce token scaduti, tentativi di accesso vecchi e contatori di rate limit.
 *
 *     php scripts/cleanup-tokens.php
 *
 * Da pianificare nel cron una volta al giorno. Rimuove solo dati tecnici
 * scaduti: nessun contenuto del sito viene toccato.
 */

use App\Console\Console;
use App\Core\Application;
use App\Repositories\AuthTokenRepository;

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

Console::title('Pulizia dati tecnici scaduti');

try {
    $purged = $app->get(AuthTokenRepository::class)->purgeExpired();

    Console::bullet(sprintf('Inviti scaduti rimossi: %d', $purged['invites']));
    Console::bullet(sprintf('Token di reset rimossi: %d', $purged['resets']));
    Console::bullet(sprintf('Tentativi di accesso rimossi: %d', $purged['attempts']));
    Console::bullet(sprintf('Contatori di frequenza rimossi: %d', $purged['rate_limits']));

    Console::line();
    Console::success('Pulizia completata.');
    exit(0);
} catch (Throwable $e) {
    Console::error($e->getMessage());
    exit(1);
}
