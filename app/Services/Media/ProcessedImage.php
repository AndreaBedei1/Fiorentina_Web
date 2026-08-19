<?php

declare(strict_types=1);

namespace App\Services\Media;

/** Esito dell'elaborazione di un'immagine. */
final readonly class ProcessedImage
{
    /** @param array<string, array{width: int, height: int}> $variants */
    public function __construct(
        public string $key,
        public string $extension,
        public int $width,
        public int $height,
        public int $filesize,
        public bool $hasWatermark,
        public array $variants = [],
    ) {
    }
}
