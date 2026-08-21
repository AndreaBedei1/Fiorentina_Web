<?php

declare(strict_types=1);

/**
 * Controlla che ogni template Twig compili.
 *
 *     php scripts/lint-views.php
 *
 * Un errore di sintassi in una pagina del pannello si scopre altrimenti solo
 * entrandoci: qui si scopre subito, e senza bisogno di autenticarsi.
 */

use App\Core\Application;
use App\Core\View\ViewRenderer;

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

$twig = $app->get(ViewRenderer::class)->twig();
$radice = $app->resourcePath('views');

$file = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice));
$errori = 0;
$totale = 0;

foreach ($file as $percorso) {
    if (! $percorso->isFile() || $percorso->getExtension() !== 'twig') {
        continue;
    }

    $nome = str_replace(DIRECTORY_SEPARATOR, '/', substr($percorso->getPathname(), strlen($radice) + 1));
    $totale++;

    try {
        $twig->load($nome);
    } catch (Throwable $e) {
        $errori++;
        printf("KO  %s\n    %s\n", $nome, $e->getMessage());
    }
}

printf("\n%d template controllati, %d con errori.\n", $totale, $errori);

exit($errori === 0 ? 0 : 1);
