<?php

declare(strict_types=1);

return [
    // auto = Imagick se presente (produzione Aruba), altrimenti GD.
    'driver' => env('IMAGE_DRIVER', 'auto'),

    'quality' => [
        'webp' => (int) env('IMAGE_QUALITY_WEBP', 82),
        'jpeg' => (int) env('IMAGE_QUALITY_JPEG', 85),
    ],

    /*
     * Dimensioni generate per ogni fotografia caricata.
     * Il lato lungo e cio che conta: le foto sono sia orizzontali sia verticali.
     */
    'sizes' => [
        'thumb' => 400,
        'medium' => 1200,
        'large' => 2000,
    ],

    // Oltre questo lato lungo l'originale viene ridimensionato prima di essere
    // archiviato: un file da 40 megapixel non serve a nessuno e satura lo spazio.
    'max_original_dimension' => 4000,

    'max_upload_bytes' => 16 * 1024 * 1024,

    'allowed_mime_types' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ],

    'watermark' => [
        'enabled' => (bool) env('WATERMARK_ENABLED', true),
        'position' => env('WATERMARK_POSITION', 'bottom-right'),
        'opacity' => (int) env('WATERMARK_OPACITY', 38),
        // Larghezza della filigrana come percentuale del lato lungo della foto.
        'scale' => (int) env('WATERMARK_SCALE', 18),
        'margin' => 3,
        // Sotto questa larghezza la filigrana coprirebbe l'immagine: non si applica.
        'min_width' => 600,
        'file' => 'images/watermark.png',
    ],
];
