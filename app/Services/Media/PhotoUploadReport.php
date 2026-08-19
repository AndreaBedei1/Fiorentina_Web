<?php

declare(strict_types=1);

namespace App\Services\Media;

/** Riepilogo di un caricamento multiplo di fotografie. */
final readonly class PhotoUploadReport
{
    /**
     * @param list<string> $errors    Messaggi relativi ai file rifiutati.
     * @param list<int>    $photoIds  ID delle fotografie effettivamente salvate.
     */
    public function __construct(
        public int $uploadedCount,
        public array $errors = [],
        public array $photoIds = [],
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function isCompleteSuccess(): bool
    {
        return $this->uploadedCount > 0 && $this->errors === [];
    }

    public function summaryMessage(): string
    {
        if ($this->uploadedCount === 0) {
            return 'Nessuna fotografia caricata.';
        }

        $base = $this->uploadedCount === 1
            ? '1 fotografia caricata.'
            : sprintf('%d fotografie caricate.', $this->uploadedCount);

        if ($this->errors === []) {
            return $base;
        }

        return $base . sprintf(' %d file non sono stati accettati.', count($this->errors));
    }
}
