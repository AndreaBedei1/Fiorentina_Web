<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Http\UploadedFile;
use App\Models\User;
use App\Repositories\AlbumRepository;
use App\Repositories\PhotoRepository;
use App\Services\AuditLogger;
use Psr\Log\LoggerInterface;

/**
 * Caricamento ed eliminazione delle fotografie della galleria.
 *
 * Il caricamento multiplo e tollerante: le fotografie valide vengono salvate
 * anche se altre nello stesso gruppo falliscono, e gli errori vengono
 * riepilogati per file. Con trenta immagini alla volta, far ricominciare tutto
 * per un solo file corrotto sarebbe inaccettabile.
 */
final class PhotoService
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly AlbumRepository $albums,
        private readonly UploadValidator $validator,
        private readonly ImageProcessor $processor,
        private readonly MediaPaths $paths,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Carica un gruppo di fotografie in un album.
     *
     * @param list<UploadedFile> $files
     */
    public function uploadToAlbum(int $albumId, array $files, User $uploader): PhotoUploadReport
    {
        $album = $this->albums->find($albumId);

        if ($album === null) {
            return new PhotoUploadReport(0, ['Album non trovato.'], []);
        }

        $uploaded = [];
        $errors = [];
        $sortOrder = $this->photos->nextSortOrder($albumId);

        foreach ($files as $file) {
            $validation = $this->validator->validateImage($file);

            if ($validation->failed()) {
                $errors[] = (string) $validation->error;

                continue;
            }

            $key = $this->paths->generateKey();

            try {
                $processed = $this->processor->process(
                    sourcePath: $file->path(),
                    collection: MediaPaths::COLLECTION_GALLERY,
                    key: $key,
                    extension: $validation->extension,
                    applyWatermark: true,
                );

                $photoId = $this->photos->create([
                    'album_id' => $albumId,
                    'storage_key' => $processed->key,
                    'extension' => $processed->extension,
                    'original_name' => $file->sanitizedClientName(),
                    'alt_text' => $this->defaultAltText($album->title),
                    'width' => $processed->width,
                    'height' => $processed->height,
                    'filesize' => $processed->filesize,
                    'taken_at' => $this->processor->extractTakenAt($file->path()),
                    'has_watermark' => $processed->hasWatermark ? 1 : 0,
                    'sort_order' => $sortOrder++,
                    'status' => 'published',
                    'uploaded_by' => $uploader->id,
                ]);

                $uploaded[] = $photoId;
            } catch (\Throwable $e) {
                $this->logger->error('Caricamento fotografia non riuscito.', [
                    'album' => $albumId,
                    'file' => $file->sanitizedClientName(),
                    'error' => $e->getMessage(),
                ]);

                // Un'elaborazione interrotta a meta può aver lasciato file su
                // disco: li rimuoviamo per non accumulare spazzatura.
                $this->paths->deleteAll(MediaPaths::COLLECTION_GALLERY, $key, $validation->extension);

                $errors[] = sprintf(
                    'Elaborazione di "%s" non riuscita. Riprova o usa un file diverso.',
                    $file->sanitizedClientName(),
                );
            }
        }

        if ($uploaded !== []) {
            $this->albums->refreshCounters($albumId);

            $this->audit->log(
                AuditLogger::PHOTOS_UPLOADED,
                'album',
                $albumId,
                sprintf('%d fotografie caricate in "%s"', count($uploaded), $album->title),
                ['count' => count($uploaded), 'errors' => count($errors)],
                $uploader,
            );
        }

        return new PhotoUploadReport(count($uploaded), $errors, $uploaded);
    }

    /** Elimina una fotografia e i relativi file su disco. */
    public function delete(int $photoId, User $actor): bool
    {
        $photo = $this->photos->find($photoId);

        if ($photo === null) {
            return false;
        }

        $this->photos->delete($photoId);
        $this->paths->deleteAll(MediaPaths::COLLECTION_GALLERY, $photo->storageKey, $photo->extension);
        $this->albums->refreshCounters($photo->albumId);

        $this->audit->log(
            AuditLogger::PHOTO_DELETED,
            'photo',
            $photoId,
            sprintf('Fotografia eliminata dall album "%s"', $photo->albumTitle ?? '?'),
            [],
            $actor,
        );

        return true;
    }

    /** Elimina tutte le fotografie di un album: usato quando l'album viene rimosso. */
    public function deleteAllForAlbum(int $albumId): int
    {
        $deleted = 0;

        foreach ($this->photos->allForAlbum($albumId) as $photo) {
            $this->paths->deleteAll(MediaPaths::COLLECTION_GALLERY, $photo->storageKey, $photo->extension);
            $this->photos->delete($photo->id);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Riapplica la filigrana a tutte le fotografie di un album.
     *
     * Utile dopo un cambio di impostazioni: senza, la nuova filigrana varrebbe
     * solo per le fotografie caricate da quel momento in poi.
     *
     * @return array{processed: int, failed: int}
     */
    public function regenerateAlbum(int $albumId): array
    {
        $processed = 0;
        $failed = 0;

        foreach ($this->photos->allForAlbum($albumId) as $photo) {
            $ok = $this->processor->regenerate(
                MediaPaths::COLLECTION_GALLERY,
                $photo->storageKey,
                $photo->extension,
                applyWatermark: true,
            );

            $ok ? $processed++ : $failed++;
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    /**
     * Testo alternativo predefinito.
     *
     * Meglio una descrizione generica ma pertinente che un attributo vuoto: chi
     * naviga con uno screen reader deve almeno sapere di cosa si tratta.
     */
    private function defaultAltText(string $albumTitle): string
    {
        return sprintf('Fotografia di Baraonda Fiorentina - %s', $albumTitle);
    }
}
