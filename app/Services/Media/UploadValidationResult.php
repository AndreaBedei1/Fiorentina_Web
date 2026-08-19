<?php

declare(strict_types=1);

namespace App\Services\Media;

/** Esito della validazione di un singolo file caricato. */
final readonly class UploadValidationResult
{
    private function __construct(
        public bool $valid,
        public ?string $error = null,
        public string $extension = 'jpg',
        public string $mimeType = '',
        public int $width = 0,
        public int $height = 0,
    ) {
    }

    public static function valid(string $extension, string $mimeType, int $width, int $height): self
    {
        return new self(true, null, $extension, $mimeType, $width, $height);
    }

    public static function invalid(string $error): self
    {
        return new self(false, $error);
    }

    public function failed(): bool
    {
        return ! $this->valid;
    }
}
