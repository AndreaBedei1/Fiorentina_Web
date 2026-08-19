<?php

declare(strict_types=1);

/**
 * Esegue le migrazioni del database.
 *
 *   php scripts/migrate.php              applica le migrazioni mancanti
 *   php scripts/migrate.php --fresh      elimina tutte le tabelle e ricrea da zero
 *   php scripts/migrate.php --rollback   annulla l'ultimo lotto
 *   php scripts/migrate.php --status     mostra lo stato senza modificare nulla
 */

use App\Console\Console;
use App\Core\Application;
use App\Core\Database\Connection;
use App\Core\Database\Migrator;

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

$options = array_slice($argv, 1);
$has = static fn (string $flag): bool => in_array($flag, $options, true);

$migrator = new Migrator(
    $app->get(Connection::class),
    $app->databasePath('migrations'),
);

try {
    if ($has('--status')) {
        Console::title('Stato migrazioni');
        $pending = $migrator->pending();
        $all = $migrator->availableMigrations();

        foreach ($all as $migration) {
            $isPending = in_array($migration, $pending, true);
            Console::bullet(($isPending ? '[ ] ' : '[x] ') . $migration);
        }

        Console::line();
        Console::info(sprintf('%d migrazioni totali, %d da applicare.', count($all), count($pending)));
        exit(0);
    }

    if ($has('--fresh')) {
        if ($app->isProduction() && ! $has('--force')) {
            Console::error('--fresh cancella tutti i dati. In produzione richiede anche --force.');
            exit(1);
        }

        Console::title('Ricostruzione database');
        $count = $migrator->fresh();
    } elseif ($has('--rollback')) {
        Console::title('Rollback ultimo lotto');
        $count = $migrator->rollback();
    } else {
        Console::title('Migrazioni database');
        $count = $migrator->migrate();
    }

    foreach ($migrator->output() as $line) {
        Console::bullet($line);
    }

    Console::line();
    Console::success(sprintf('Operazione completata (%d migrazioni).', $count));
    exit(0);
} catch (Throwable $e) {
    Console::line();
    Console::error($e->getMessage());
    exit(1);
}
