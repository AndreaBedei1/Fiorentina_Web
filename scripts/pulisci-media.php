<?php

declare(strict_types=1);

/**
 * Toglie dal disco le immagini che nessuna riga del database rivendica.
 *
 *     php scripts/pulisci-media.php            elenca soltanto
 *     php scripts/pulisci-media.php --elimina  toglie davvero i file
 *
 * Perche serve: le fotografie vivono in due posti, il database (la riga che
 * dice a quale notizia o prodotto appartengono) e il disco (l'originale piu
 * le copie ridimensionate). Se una riga sparisce senza portarsi via i file -
 * per un errore, per un'importazione andata a meta, o per le righe eliminate
 * prima che "elimina" eliminasse davvero - restano file che nessuno servira
 * mai piu, e che nessuno sa piu di avere.
 *
 * Il comando non indovina niente: costruisce l'elenco delle chiavi ancora
 * vive leggendo il database, e considera orfano solo cio che non vi compare.
 * Di suo non cancella: senza --elimina si limita a dire cosa toglierebbe.
 */

use App\Console\Console;
use App\Core\Application;
use App\Core\Database\Connection;
use App\Services\Media\MediaPaths;

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

$db = $app->get(Connection::class);
$elimina = in_array('--elimina', $argv, true);

Console::title('PULIZIA DEI FILE ORFANI');

/** Chiavi ancora rivendicate da una riga, raggruppate per collezione. */
$vive = [
    MediaPaths::COLLECTION_NEWS => $db->column('SELECT image_key FROM news WHERE image_key IS NOT NULL'),
    MediaPaths::COLLECTION_EVENTS => $db->column('SELECT image_key FROM events WHERE image_key IS NOT NULL'),
    MediaPaths::COLLECTION_GALLERY => $db->column('SELECT storage_key FROM photos'),
    MediaPaths::COLLECTION_PRODUCTS => $db->column('SELECT storage_key FROM product_images'),
    MediaPaths::COLLECTION_MEMBERS => $db->column('SELECT photo_key FROM organization_members WHERE photo_key IS NOT NULL'),
    MediaPaths::COLLECTION_PAGES => $db->column('SELECT hero_image_key FROM pages WHERE hero_image_key IS NOT NULL'),
    MediaPaths::COLLECTION_SOCIAL => $db->column('SELECT local_thumb_key FROM social_posts WHERE local_thumb_key IS NOT NULL'),
];

$radici = [
    $app->basePath('public' . DIRECTORY_SEPARATOR . 'uploads'),
    $app->storagePath('originals'),
];

$orfani = [];
$totale = 0;

foreach ($radici as $radice) {
    if (! is_dir($radice)) {
        continue;
    }

    $file = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice, FilesystemIterator::SKIP_DOTS));

    foreach ($file as $percorso) {
        if (! $percorso->isFile()) {
            continue;
        }

        $relativo = str_replace(DIRECTORY_SEPARATOR, '/', substr($percorso->getPathname(), strlen($radice) + 1));
        $pezzi = explode('/', $relativo);
        $collezione = array_shift($pezzi);

        if (! isset($vive[$collezione])) {
            continue;
        }

        $totale++;

        /*
         * Dal nome del file alla chiave: "2026/08/ab12cd-large.jpg" e
         * "2026/08/ab12cd.png" appartengono entrambi alla chiave
         * "2026/08/ab12cd". Si toglie l'estensione e poi l'eventuale suffisso
         * della misura, che e sempre l'ultimo pezzo dopo un trattino.
         */
        $nome = (string) array_pop($pezzi);
        $senzaEstensione = pathinfo($nome, PATHINFO_FILENAME);
        $chiave = implode('/', array_merge($pezzi, [preg_replace('/-(thumb|medium|large)$/', '', $senzaEstensione)]));

        if (! in_array($chiave, $vive[$collezione], true)) {
            $orfani[] = $percorso->getPathname();
        }
    }
}

if ($orfani === []) {
    Console::success(sprintf('%d file controllati: nessun orfano.', $totale));

    exit(0);
}

foreach ($orfani as $percorso) {
    Console::bullet(str_replace($app->basePath(''), '', $percorso));
}

if (! $elimina) {
    Console::warn(sprintf(
        '%d file orfani su %d controllati. Rilancia con --elimina per toglierli.',
        count($orfani),
        $totale,
    ));

    exit(0);
}

$tolti = 0;

foreach ($orfani as $percorso) {
    if (@unlink($percorso)) {
        $tolti++;
    }
}

Console::success(sprintf('%d file orfani eliminati su %d controllati.', $tolti, $totale));
