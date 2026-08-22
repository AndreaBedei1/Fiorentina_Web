<?php

declare(strict_types=1);

/**
 * Controlla che i collegamenti scritti nei template esistano davvero.
 *
 *     php scripts/lint-rotte.php
 *
 * Perche serve: `route('shop.show', { slug: ... })` e sintatticamente
 * perfetto, e `composer lint:views` lo lascia passare senza fiatare. Se pero
 * quella rotta si chiama il suo parametro `riferimento`, la pagina esplode -
 * ma solo quando qualcuno ci arriva davvero, e non e detto che sia uno di noi.
 *
 * E successo con il carrello: dopo il passaggio agli indirizzi con id, due
 * righe di cart.twig chiedevano ancora `slug`. Il carrello vuoto funzionava,
 * quello pieno no, e nessuna prova automatica ci passava.
 *
 * Il comando quindi confronta due elenchi: i nomi e i parametri delle rotte
 * dichiarate, e i nomi e i parametri usati nei template. Non esegue niente e
 * non tocca niente.
 */

use App\Console\Console;
use App\Core\Application;
use App\Core\Routing\Router;

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

Console::title('COLLEGAMENTI NEI TEMPLATE');

/*
 * Le rotte dichiarate, con i parametri che ciascuna si aspetta.
 *
 * @var array<string, list<string>> $rotte
 */
$rotte = [];

foreach ($app->get(Router::class)->routes() as $rotta) {
    $nome = $rotta->getName();

    if ($nome === null) {
        continue;
    }

    $rotte[$nome] = $rotta->getParameterNames();
}

Console::bullet(sprintf('%d rotte con un nome.', count($rotte)));

$radice = $app->basePath('resources' . DIRECTORY_SEPARATOR . 'views');
$file = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice, FilesystemIterator::SKIP_DOTS));

$problemi = [];
$controllati = 0;

foreach ($file as $percorso) {
    if (! $percorso->isFile() || $percorso->getExtension() !== 'twig') {
        continue;
    }

    $controllati++;
    $testo = (string) file_get_contents($percorso->getPathname());
    $righe = explode("\n", $testo);

    foreach ($righe as $numero => $riga) {
        // route('nome') oppure route('nome', { chiave: ..., altra: ... })
        preg_match_all("/\\broute\\(\\s*'([a-zA-Z0-9_.]+)'\\s*(?:,\\s*\\{([^}]*)\\})?/", $riga, $usi, PREG_SET_ORDER);

        foreach ($usi as $uso) {
            $nome = $uso[1];
            $relativo = str_replace($radice . DIRECTORY_SEPARATOR, '', $percorso->getPathname());
            $dove = sprintf('%s:%d', str_replace(DIRECTORY_SEPARATOR, '/', $relativo), $numero + 1);

            if (! isset($rotte[$nome])) {
                $problemi[] = sprintf('%s  rotta inesistente: %s', $dove, $nome);

                continue;
            }

            preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)\s*:/', $uso[2] ?? '', $chiavi);
            $passati = $chiavi[1];
            $attesi = $rotte[$nome];

            foreach (array_diff($passati, $attesi) as $inutile) {
                $problemi[] = sprintf(
                    '%s  %s non ha un parametro "%s"%s',
                    $dove,
                    $nome,
                    $inutile,
                    $attesi === [] ? ' (non ne ha nessuno)' : ' (vuole: ' . implode(', ', $attesi) . ')',
                );
            }

            foreach (array_diff($attesi, $passati) as $mancante) {
                $problemi[] = sprintf('%s  a %s manca il parametro "%s"', $dove, $nome, $mancante);
            }
        }
    }
}

if ($problemi === []) {
    Console::success(sprintf('%d template controllati, nessun collegamento rotto.', $controllati));

    exit(0);
}

foreach ($problemi as $problema) {
    Console::bullet($problema);
}

Console::error(sprintf('%d collegamenti da correggere su %d template.', count($problemi), $controllati));

exit(1);
