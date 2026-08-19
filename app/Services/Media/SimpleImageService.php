<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Http\UploadedFile;

/**
 * Immagine singola associata a un contenuto: notizia, evento, prodotto,
 * persona del direttivo, testata di pagina.
 *
 * Rispetto alle fotografie della galleria non c'e filigrana (su un prodotto
 * sarebbe controproducente) e la gestione e piu semplice: un solo file per
 * contenuto, sostituibile.
 */
final class SimpleImageService
{
    public function __construct(
        private readonly UploadValidator $validator,
        private readonly ImageProcessor $processor,
        private readonly MediaPaths $paths,
    ) {
    }

    /**
     * Salva una nuova immagine, eliminando l'eventuale precedente.
     *
     * @param string|null $previousKey Chiave da rimuovere in caso di sostituzione.
     * @return array{key: string|null, extension: string|null, error: string|null}
     */
    public function store(
        UploadedFile $file,
        string $collection,
        ?string $previousKey = null,
        ?string $previousExtension = null,
    ): array {
        $validation = $this->validator->validateImage($file);

        if ($validation->failed()) {
            return ['key' => null, 'extension' => null, 'error' => $validation->error];
        }

        $key = $this->paths->generateKey();

        try {
            $processed = $this->processor->process(
                sourcePath: $file->path(),
                collection: $collection,
                key: $key,
                extension: $validation->extension,
                applyWatermark: false,
            );
        } catch (\Throwable $e) {
            $this->paths->deleteAll($collection, $key, $validation->extension);

            return ['key' => null, 'extension' => null, 'error' => 'Elaborazione dell immagine non riuscita.'];
        }

        // La vecchia immagine si elimina solo a nuova elaborazione riuscita:
        // in caso di errore il contenuto conserva quella che aveva.
        if ($previousKey !== null && $previousKey !== '') {
            $this->paths->deleteAll($collection, $previousKey, $previousExtension ?? 'jpg');
        }

        return ['key' => $processed->key, 'extension' => $processed->extension, 'error' => null];
    }

    public function delete(string $collection, ?string $key, ?string $extension = null): void
    {
        if ($key === null || $key === '') {
            return;
        }

        $this->paths->deleteAll($collection, $key, $extension ?? 'jpg');
    }
}
