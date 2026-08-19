<?php

declare(strict_types=1);

/**
 * Genera i segnaposto grafici in formato raster: favicon PNG e filigrana.
 *
 *     php scripts/generate-placeholders.php
 *
 * Sono disegnati con GD, quindi non serve nessun file binario nel repository e
 * il risultato e riproducibile. Vanno sostituiti con il materiale ufficiale del
 * gruppo: il logo definitivo va in resources/images/watermark.png e in
 * resources/static/logo.svg, senza altre modifiche al codice.
 */

use App\Console\Console;

require __DIR__ . '/bootstrap.php';

if (! extension_loaded('gd')) {
    Console::error('Estensione GD non disponibile: impossibile generare i segnaposto.');
    exit(1);
}

Console::title('Generazione segnaposto grafici');

$root = dirname(__DIR__);

/** Colori del design system. */
$palette = [
    'viola' => [0x41, 0x21, 0x5f],
    'violaScuro' => [0x1a, 0x0d, 0x27],
    'rosso' => [0xcd, 0x22, 0x47],
    'bianco' => [0xf3, 0xf1, 0xed],
];

/**
 * Disegna il marchio segnaposto: scudo con giglio stilizzato.
 *
 * Le proporzioni sono espresse in frazioni del lato, cosi lo stesso disegno
 * funziona a 32 come a 512 pixel.
 */
function drawMark(GdImage $image, int $size, array $palette, bool $transparentBackground = false): void
{
    $white = imagecolorallocatealpha($image, ...[...$palette['bianco'], 0]);
    $red = imagecolorallocatealpha($image, ...[...$palette['rosso'], 0]);

    if (! $transparentBackground) {
        $violet = imagecolorallocate($image, ...$palette['viola']);
        $dark = imagecolorallocate($image, ...$palette['violaScuro']);

        imagefilledrectangle($image, 0, 0, $size, $size, $violet);

        // Banda diagonale piu scura: da profondita senza aggiungere dettagli
        // che a 32 pixel diventerebbero illeggibili.
        imagefilledpolygon($image, [
            0, $size,
            $size, (int) ($size * 0.45),
            $size, $size,
        ], $dark);
    }

    $cx = $size / 2;

    // --- Scudo ---
    $shield = [
        (int) ($cx - $size * 0.26), (int) ($size * 0.20),
        (int) ($cx + $size * 0.26), (int) ($size * 0.20),
        (int) ($cx + $size * 0.26), (int) ($size * 0.52),
        (int) $cx,                  (int) ($size * 0.82),
        (int) ($cx - $size * 0.26), (int) ($size * 0.52),
    ];
    imagefilledpolygon($image, $shield, $white);

    // --- Giglio stilizzato dentro lo scudo ---
    $stemWidth = max(2, (int) ($size * 0.055));
    imagefilledrectangle(
        $image,
        (int) ($cx - $stemWidth / 2),
        (int) ($size * 0.30),
        (int) ($cx + $stemWidth / 2),
        (int) ($size * 0.70),
        $red,
    );

    // Petali laterali: due triangoli speculari.
    imagefilledpolygon($image, [
        (int) ($cx - $size * 0.04), (int) ($size * 0.44),
        (int) ($cx - $size * 0.19), (int) ($size * 0.36),
        (int) ($cx - $size * 0.10), (int) ($size * 0.58),
    ], $red);

    imagefilledpolygon($image, [
        (int) ($cx + $size * 0.04), (int) ($size * 0.44),
        (int) ($cx + $size * 0.19), (int) ($size * 0.36),
        (int) ($cx + $size * 0.10), (int) ($size * 0.58),
    ], $red);

    // Punta superiore.
    imagefilledpolygon($image, [
        (int) $cx,                  (int) ($size * 0.26),
        (int) ($cx - $size * 0.07), (int) ($size * 0.38),
        (int) ($cx + $size * 0.07), (int) ($size * 0.38),
    ], $red);

    // Traversa.
    imagefilledrectangle(
        $image,
        (int) ($cx - $size * 0.13),
        (int) ($size * 0.545),
        (int) ($cx + $size * 0.13),
        (int) ($size * 0.575),
        $red,
    );
}

function createIcon(int $size, array $palette): GdImage
{
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, true);
    imagesavealpha($image, true);
    imageantialias($image, true);

    drawMark($image, $size, $palette);

    return $image;
}

// -----------------------------------------------------------------------------
//  Favicon e icone dell'applicazione
// -----------------------------------------------------------------------------
$icons = [
    'public/favicon/favicon-32.png' => 32,
    'public/favicon/apple-touch-icon.png' => 180,
    'public/favicon/icon-192.png' => 192,
    'public/favicon/icon-512.png' => 512,
];

foreach ($icons as $relativePath => $size) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    ensure_directory(dirname($path));

    $image = createIcon($size, $palette);
    imagepng($image, $path, 9);
    imagedestroy($image);

    Console::bullet(sprintf('%s (%dx%d)', $relativePath, $size, $size));
}

// -----------------------------------------------------------------------------
//  Filigrana
// -----------------------------------------------------------------------------
/*
 * Sfondo trasparente e marchio bianco: l'opacita finale la decide
 * WatermarkService al momento dell'applicazione, quindi qui il disegno resta
 * pieno e viene reso semitrasparente solo sulla fotografia.
 */
$watermarkSize = 512;
$watermark = imagecreatetruecolor($watermarkSize, $watermarkSize);
imagealphablending($watermark, false);
imagesavealpha($watermark, true);
imagefilledrectangle($watermark, 0, 0, $watermarkSize, $watermarkSize, imagecolorallocatealpha($watermark, 0, 0, 0, 127));
imagealphablending($watermark, true);
imageantialias($watermark, true);

drawMark($watermark, $watermarkSize, $palette, transparentBackground: true);

$watermarkPath = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'watermark.png';
ensure_directory(dirname($watermarkPath));
imagepng($watermark, $watermarkPath, 9);
imagedestroy($watermark);

Console::bullet('resources/images/watermark.png (512x512, sfondo trasparente)');

Console::line();
Console::success('Segnaposto generati.');
Console::line();
Console::warn('Ricorda di sostituirli con il materiale ufficiale del gruppo:');
Console::bullet('resources/images/watermark.png  -> logo per la filigrana (PNG con trasparenza)');
Console::bullet('resources/static/logo.svg       -> logo dell intestazione');
Console::bullet('resources/static/hero.svg       -> fotografia di apertura');
