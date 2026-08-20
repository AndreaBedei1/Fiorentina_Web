<?php

declare(strict_types=1);

/**
 * Rimuove file temporanei, cache e log vecchi.
 *
 *     php scripts/cleanup-temp-files.php
 *
 * Da pianificare nel cron una volta a settimana. Su hosting condiviso lo
 * spazio e limitato: senza questa pulizia, cache di Twig e log si accumulano
 * finché qualcuno non se ne accorge, di solito nel momento peggiore.
 */

use App\Console\Console;
use App\Core\Application;

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

Console::title('Pulizia file temporanei');

/**
 * Elimina i file di una directory più vecchi di N giorni.
 *
 * @return array{files: int, bytes: int}
 */
function purgeOlderThan(string $directory, int $days, array $extensions = []): array
{
    if (! is_dir($directory)) {
        return ['files' => 0, 'bytes' => 0];
    }

    $threshold = time() - $days * 86400;
    $files = 0;
    $bytes = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }

        if ($extensions !== [] && ! in_array(strtolower($file->getExtension()), $extensions, true)) {
            continue;
        }

        if ($file->getMTime() > $threshold) {
            continue;
        }

        $size = $file->getSize();

        if (@unlink($file->getPathname())) {
            $files++;
            $bytes += $size;
        }
    }

    return ['files' => $files, 'bytes' => $bytes];
}

function formatBytes(int $bytes): string
{
    return $bytes >= 1024 * 1024
        ? number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB'
        : number_format($bytes / 1024, 0, ',', '.') . ' KB';
}

try {
    $total = 0;

    // File temporanei degli upload interrotti.
    $temp = purgeOlderThan($app->storagePath('temp'), 1);
    Console::bullet(sprintf('Temporanei: %d file (%s)', $temp['files'], formatBytes($temp['bytes'])));
    $total += $temp['files'];

    // Cache dei template compilati: si rigenera da sola alla prima richiesta.
    $cache = purgeOlderThan($app->storagePath('cache'), 30);
    Console::bullet(sprintf('Cache: %d file (%s)', $cache['files'], formatBytes($cache['bytes'])));
    $total += $cache['files'];

    // Log applicativi: Monolog ne tiene 14, questo copre gli avanzi.
    $logs = purgeOlderThan($app->storagePath('logs'), 60, ['log']);
    Console::bullet(sprintf('Log: %d file (%s)', $logs['files'], formatBytes($logs['bytes'])));
    $total += $logs['files'];

    // Email di sviluppo salvate su file.
    $mail = purgeOlderThan($app->storagePath('logs/mail'), 14, ['eml']);
    Console::bullet(sprintf('Email di sviluppo: %d file (%s)', $mail['files'], formatBytes($mail['bytes'])));
    $total += $mail['files'];

    // Sessioni scadute non ripulite dal garbage collector di PHP.
    $sessions = purgeOlderThan($app->storagePath('sessions'), 7);
    Console::bullet(sprintf('Sessioni scadute: %d file', $sessions['files']));
    $total += $sessions['files'];

    Console::line();
    Console::success(sprintf('Rimossi %d file.', $total));
    exit(0);
} catch (Throwable $e) {
    Console::error($e->getMessage());
    exit(1);
}
