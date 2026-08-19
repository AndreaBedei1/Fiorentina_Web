<?php

declare(strict_types=1);

/**
 * Sincronizza il calendario delle partite dal fornitore configurato.
 *
 *     php scripts/sync-football.php
 *
 * Da pianificare nel cron di Aruba, tipicamente due volte al giorno.
 * L operazione e idempotente: rieseguirla aggiorna i dati senza duplicarli, e
 * le partite inserite a mano dagli amministratori non vengono toccate.
 *
 * Senza chiave API il fornitore ripiega su quello dimostrativo, quindi lo
 * script non fallisce mai per configurazione incompleta.
 */

use App\Console\Console;
use App\Core\Application;
use App\Services\Football\FootballService;

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

Console::title('Sincronizzazione calendario partite');

try {
    $report = $app->get(FootballService::class)->sync();

    Console::bullet($report->summary());

    foreach ($report->errors as $error) {
        Console::warn($error);
    }

    if ($report->hasErrors()) {
        Console::line();
        Console::warn('Sincronizzazione completata con avvisi.');
        exit(0);
    }

    Console::line();
    Console::success('Calendario aggiornato.');
    exit(0);
} catch (Throwable $e) {
    Console::error($e->getMessage());
    exit(1);
}
