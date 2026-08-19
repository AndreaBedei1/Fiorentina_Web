<?php

declare(strict_types=1);

/**
 * Popola il database con i dati dimostrativi.
 *
 *     php scripts/seed.php               esegue tutti i seeder
 *     php scripts/seed.php --only=shop   esegue solo il seeder indicato
 *     php scripts/seed.php --list        elenca i seeder disponibili
 *
 * Ogni seeder e idempotente: rilanciare il comando non crea duplicati.
 */

use App\Console\Console;
use App\Core\Application;
use App\Core\Database\Connection;
use Database\Seeders\ContentSeeder;
use Database\Seeders\GallerySeeder;
use Database\Seeders\IntegrationSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\PageSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ShopSeeder;
use Database\Seeders\AdminSeeder;
use Database\Seeders\TaxonomySeeder;

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

/*
 * L'ordine conta: le impostazioni servono a tutto il resto, l'amministratore e
 * l'autore dei contenuti, le tassonomie sono referenziate da eventi e prodotti.
 */
$seeders = [
    'settings' => SettingsSeeder::class,
    'admin' => AdminSeeder::class,
    'taxonomy' => TaxonomySeeder::class,
    'pages' => PageSeeder::class,
    'organization' => OrganizationSeeder::class,
    'content' => ContentSeeder::class,
    'gallery' => GallerySeeder::class,
    'shop' => ShopSeeder::class,
    'integrations' => IntegrationSeeder::class,
];

$options = array_slice($argv, 1);

if (in_array('--list', $options, true)) {
    Console::title('Seeder disponibili');
    foreach (array_keys($seeders) as $key) {
        Console::bullet($key);
    }
    exit(0);
}

$only = null;

foreach ($options as $option) {
    if (str_starts_with($option, '--only=')) {
        $only = substr($option, 7);
    }
}

if ($only !== null && ! isset($seeders[$only])) {
    Console::error(sprintf('Seeder "%s" inesistente. Usa --list per vedere quelli disponibili.', $only));
    exit(1);
}

if ($only !== null) {
    $seeders = [$only => $seeders[$only]];
}

Console::title('Dati dimostrativi');

$connection = $app->get(Connection::class);
$total = 0;
$failed = 0;

foreach ($seeders as $key => $class) {
    /** @var Database\Seeders\Seeder $seeder */
    $seeder = new $class($app, $connection);

    Console::line();
    Console::info($seeder->name());

    try {
        $total += $seeder->run();
    } catch (Throwable $e) {
        $failed++;
        Console::error(sprintf('%s: %s', $key, $e->getMessage()));

        if ($app->isDebug()) {
            Console::bullet($e->getFile() . ':' . $e->getLine());
        }
    }
}

Console::line();

if ($failed > 0) {
    Console::error(sprintf('%d seeder non completati. Elementi creati: %d.', $failed, $total));
    exit(1);
}

Console::success(sprintf('Completato: %d elementi creati.', $total));

if ($app->isLocal() && env('DEV_ADMIN_PASSWORD', '') !== '') {
    Console::line();
    Console::warn('Credenziali di sviluppo (valide solo in locale):');
    Console::bullet('Indirizzo: ' . rtrim((string) config('app.url'), '/') . '/admin');
    Console::bullet('Email:     ' . env('DEV_ADMIN_EMAIL'));
    Console::bullet('Password:  quella impostata in DEV_ADMIN_PASSWORD nel file .env');
}
