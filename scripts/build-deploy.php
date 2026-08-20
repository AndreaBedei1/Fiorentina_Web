<?php

declare(strict_types=1);

/**
 * Prepara l'artefatto da caricare su Aruba via FTP.
 *
 *     php scripts/build-deploy.php
 *
 * Raccoglie in build/deploy/ solo cio che serve in produzione: codice, vendor,
 * asset già compilati, template. Restano fuori sorgenti dei fogli di stile,
 * node_modules, test, script di sviluppo e soprattutto il file .env, che
 * contiene i segreti e va configurato direttamente sul server.
 *
 * Prima di lanciarlo:
 *     composer install --no-dev --optimize-autoloader
 *     npm run build
 */

use App\Console\Console;
use App\Core\Application;

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

$root = dirname(__DIR__);
$target = $root . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'deploy';

/** Cartelle e file da includere nell'artefatto. */
const INCLUDE_PATHS = [
    'app',
    'config',
    'database/migrations',
    'database/seeds',
    'public',
    'resources/views',
    'resources/images',
    'routes',
    'scripts',
    'vendor',
    'composer.json',
    'composer.lock',
];

/** Percorsi da escludere anche se contenuti in quelli inclusi. */
const EXCLUDE_PATTERNS = [
    '.env',
    '.git',
    'node_modules',
    'public/hot',
    // Router del server integrato di PHP: serve solo in sviluppo, in produzione
    // se ne occupa Apache.
    'public/router.php',
    // Contenuti caricati dagli amministratori: sul server esistono già e non
    // vanno sovrascritti. In locale sono fotografie dimostrative, che sul sito
    // vero non hanno alcun motivo di finire. La cartella viene ricreata vuota
    // più avanti.
    'public/uploads',
    'storage/logs',
    'storage/cache',
    'storage/temp',
    'storage/sessions',
    'scripts/build-deploy.php',
    'scripts/generate-placeholders.php',
    'scripts/check-contrast.php',
];

function isExcluded(string $relative): bool
{
    $relative = str_replace('\\', '/', $relative);

    foreach (EXCLUDE_PATTERNS as $pattern) {
        if ($relative === $pattern || str_starts_with($relative, $pattern . '/')) {
            return true;
        }
    }

    // File di sviluppo che non hanno motivo di finire sul server.
    return (bool) preg_match('#(^|/)(\.gitignore|\.gitkeep|\.DS_Store|Thumbs\.db)$#', $relative)
        || str_ends_with($relative, '.map');
}

/** @return array{files: int, bytes: int} */
function copyPath(string $source, string $destination, string $relativeBase = ''): array
{
    $files = 0;
    $bytes = 0;

    if (is_file($source)) {
        if (isExcluded($relativeBase)) {
            return ['files' => 0, 'bytes' => 0];
        }

        ensure_directory(dirname($destination));
        copy($source, $destination);

        return ['files' => 1, 'bytes' => (int) filesize($source)];
    }

    if (! is_dir($source)) {
        return ['files' => 0, 'bytes' => 0];
    }

    ensure_directory($destination);

    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $childRelative = $relativeBase === '' ? $entry : $relativeBase . '/' . $entry;

        if (isExcluded($childRelative)) {
            continue;
        }

        $result = copyPath(
            $source . DIRECTORY_SEPARATOR . $entry,
            $destination . DIRECTORY_SEPARATOR . $entry,
            $childRelative,
        );

        $files += $result['files'];
        $bytes += $result['bytes'];
    }

    return ['files' => $files, 'bytes' => $bytes];
}

function removeDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($path);
}

Console::title('Preparazione artefatto di produzione');

// --- Controlli preliminari ---------------------------------------------------
$problems = [];

if (! is_file($root . '/public/assets/.vite/manifest.json')) {
    $problems[] = 'Asset non compilati: eseguire prima `npm run build`.';
}

if (is_dir($root . '/vendor/phpunit')) {
    $problems[] = 'La cartella vendor contiene le dipendenze di sviluppo: eseguire `composer install --no-dev --optimize-autoloader`.';
}

if ($problems !== []) {
    foreach ($problems as $problem) {
        Console::error($problem);
    }

    Console::line();
    Console::warn('Artefatto non generato.');
    exit(1);
}

// --- Copia -------------------------------------------------------------------
Console::info('Pulizia della cartella di destinazione...');
removeDirectory($target);
ensure_directory($target);

$totalFiles = 0;
$totalBytes = 0;

foreach (INCLUDE_PATHS as $path) {
    $result = copyPath(
        $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path),
        $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path),
        $path,
    );

    $totalFiles += $result['files'];
    $totalBytes += $result['bytes'];

    Console::bullet(sprintf('%-28s %5d file  %8s', $path, $result['files'], number_format($result['bytes'] / 1024 / 1024, 1, ',', '.') . ' MB'));
}

// --- Cartelle scrivibili vuote ----------------------------------------------
// Devono esistere sul server ma partire vuote: contengono dati runtime.
foreach ([
    'storage/originals', 'storage/processed', 'storage/products',
    'storage/logs', 'storage/logs/mail', 'storage/cache', 'storage/cache/twig',
    'storage/temp', 'storage/sessions', 'storage/framework',
    'public/uploads',
] as $directory) {
    $path = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    ensure_directory($path);
    file_put_contents($path . DIRECTORY_SEPARATOR . '.gitkeep', '');
}

// --- .htaccess di protezione -------------------------------------------------
$deny = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
    . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";

foreach (['storage', 'app', 'config', 'database', 'resources', 'routes', 'scripts', 'vendor'] as $directory) {
    file_put_contents($target . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . '.htaccess', $deny);
}

// --- Promemoria ---------------------------------------------------------------
$generatedAt = date('d/m/Y H:i');

file_put_contents($target . DIRECTORY_SEPARATOR . 'LEGGIMI-DEPLOY.txt', <<<TXT
ARTEFATTO DI PRODUZIONE - Baraonda Fiorentina
Generato il {$generatedAt}

COSA MANCA E VA FATTO SUL SERVER
--------------------------------
1. Creare il file .env partendo da .env.example e compilarlo con:
   - APP_ENV=production
   - APP_DEBUG=false
   - APP_URL=https://www.baraondafiorentina.it
   - APP_KEY  (generare con: php scripts/generate-key.php)
   - credenziali DB_* del database MySQL di Aruba
   - MAIL_MAILER=smtp e credenziali SMTP Aruba
   - SESSION_SECURE=true e FORCE_HTTPS=true

2. Importare lo schema del database:
   - da phpMyAdmin, importando in ordine i file di database/migrations/
   - oppure, se disponibile la CLI: php scripts/migrate.php

3. Creare il primo amministratore:
   - php scripts/create-admin.php  (se la CLI e disponibile)
   - altrimenti seguire la procedura descritta in docs/DEPLOY_ARUBA.md

4. Impostare i permessi di scrittura (755) su:
   - storage/ e tutte le sue sottocartelle
   - public/uploads/

5. Configurare il document root del dominio sulla cartella public/.
   Se il pannello non lo consente, seguire la variante descritta in
   docs/DEPLOY_ARUBA.md.

6. Pianificare i cron descritti in docs/DEPLOY_ARUBA.md.

La documentazione completa e in docs/DEPLOY_ARUBA.md.
TXT);

Console::line();
Console::success(sprintf(
    'Artefatto pronto: %d file, %s.',
    $totalFiles,
    number_format($totalBytes / 1024 / 1024, 1, ',', '.') . ' MB',
));
Console::bullet('Cartella: build/deploy/');
Console::line();
Console::warn('Il file .env NON e incluso: va creato e compilato direttamente sul server.');
Console::bullet('Istruzioni complete: docs/DEPLOY_ARUBA.md');
