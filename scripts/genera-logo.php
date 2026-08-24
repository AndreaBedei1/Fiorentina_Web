<?php

declare(strict_types=1);

/**
 * Ricava dal logo originale tutte le misure che servono al sito.
 *
 *     php scripts/genera-logo.php
 *
 * L'originale sta in resources/images/logo-originale.png ed e l'unico file da
 * sostituire quando il logo cambia: da li nascono la copia che il sito serve
 * ai visitatori e le icone del browser.
 *
 * Perche non si serve direttamente l'originale: e grande piu di mille pixel
 * per lato e pesa un terzo di megabyte, mentre nelle pagine compare a
 * trentasei-quarantaquattro pixel. Sarebbe un terzo di megabyte scaricato da
 * ogni visitatore per disegnare un francobollo.
 *
 * Perche resta un PNG e non diventa un SVG: l'originale e un'immagine a
 * punti, non un disegno vettoriale. Convertirlo vorrebbe dire o infilare la
 * stessa immagine dentro un involucro SVG - stesso peso, nessun guadagno -
 * oppure farla ricalcare a un programma, che di un logo con scritte in
 * cerchio restituisce una brutta copia. Il PNG con trasparenza fa esattamente
 * quello che serve.
 */

use App\Console\Console;
use App\Core\Application;

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

/**
 * Misure da produrre: percorso => lato in pixel.
 *
 * Il logo del sito e 128px perche il posto piu grande in cui compare sono i
 * 64 pixel delle pagine d'errore: 128 lo rende nitido anche sugli schermi che
 * disegnano due punti per pixel, e si ferma li.
 */
$misure = [
    'resources/static/logo.png' => 128,
    'public/favicon/favicon-32.png' => 32,
    'public/favicon/apple-touch-icon.png' => 180,
    'public/favicon/icon-192.png' => 192,
    'public/favicon/icon-512.png' => 512,
];

$originale = $app->basePath('resources/images/logo-originale.png');

Console::title('MISURE DEL LOGO');

if (! is_file($originale)) {
    Console::error('Manca resources/images/logo-originale.png: e da li che si parte.');

    exit(1);
}

if (! function_exists('imagecreatefrompng')) {
    Console::error('Estensione GD non disponibile: impossibile ridimensionare.');

    exit(1);
}

$sorgente = @imagecreatefrompng($originale);

if ($sorgente === false) {
    Console::error('Il file non e un PNG leggibile.');

    exit(1);
}

$latoOriginale = imagesx($sorgente);

Console::bullet(sprintf(
    'Originale: %d x %d, %s KB',
    $latoOriginale,
    imagesy($sorgente),
    number_format(filesize($originale) / 1024, 1),
));

Console::line();

foreach ($misure as $relativo => $lato) {
    $destinazione = $app->basePath($relativo);

    if (! is_dir(dirname($destinazione))) {
        mkdir(dirname($destinazione), 0775, true);
    }

    $copia = imagecreatetruecolor($lato, $lato);

    /*
     * Senza queste tre righe la trasparenza va persa e il logo finisce dentro
     * un quadrato nero: il tondo bianco sul viola della testata diventerebbe
     * un francobollo storto.
     */
    imagealphablending($copia, false);
    imagesavealpha($copia, true);
    imagefill($copia, 0, 0, imagecolorallocatealpha($copia, 0, 0, 0, 127));

    imagecopyresampled($copia, $sorgente, 0, 0, 0, 0, $lato, $lato, $latoOriginale, $latoOriginale);
    imagepng($copia, $destinazione, 9);
    imagedestroy($copia);

    Console::bullet(sprintf('%-38s %3dpx  %6s KB', $relativo, $lato, number_format(filesize($destinazione) / 1024, 1)));
}

imagedestroy($sorgente);

Console::line();
Console::success('Fatto. Ricorda `npm run build` per copiare il logo in public/assets/.');
