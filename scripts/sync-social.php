<?php

declare(strict_types=1);

/**
 * Sincronizza i contenuti social (Instagram, Facebook, YouTube).
 *
 *     php scripts/sync-social.php
 *
 * Da pianificare nel cron, indicativamente ogni sei ore.
 *
 * Nota importante: la sincronizzazione non cancella mai i contenuti gia
 * salvati. Se le API non rispondono, il sito continua a mostrare l ultimo
 * stato utile invece di una sezione vuota.
 */

use App\Console\Console;
use App\Core\Application;
use App\Services\Social\SocialService;

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

Console::title('Sincronizzazione contenuti social');

try {
    $report = $app->get(SocialService::class)->sync();

    Console::bullet($report->summary());

    foreach ($report->errors() as $error) {
        Console::warn($error);
    }

    Console::line();

    if ($report->total() === 0) {
        Console::warn('Nessun contenuto sincronizzato: i post gia presenti restano visibili.');
        exit(0);
    }

    Console::success('Contenuti social aggiornati.');
    exit(0);
} catch (Throwable $e) {
    Console::error($e->getMessage());
    exit(1);
}
