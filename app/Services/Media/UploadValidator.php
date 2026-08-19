<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Config;
use App\Core\Http\UploadedFile;

/**
 * Controlli di sicurezza sui file caricati.
 *
 * L'ordine dei controlli non e casuale: prima gli esiti dell'upload PHP, poi la
 * dimensione, poi il tipo reale letto dai magic bytes e infine la prova che il
 * file sia davvero un'immagine decodificabile. Un file rinominato in .jpg che
 * contiene PHP fallisce al terzo controllo, e non arriva mai su disco.
 */
final class UploadValidator
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Valida un file immagine.
     *
     * @return UploadValidationResult Esito con estensione normalizzata e dimensioni.
     */
    public function validateImage(UploadedFile $file, ?int $maxBytes = null): UploadValidationResult
    {
        if ($file->isEmpty()) {
            return UploadValidationResult::invalid('Nessun file selezionato.');
        }

        if (! $file->isValid()) {
            return UploadValidationResult::invalid(
                $file->errorMessage() ?? 'Caricamento del file non riuscito.'
            );
        }

        $maxBytes ??= $this->config->int('image.max_upload_bytes', 16 * 1024 * 1024);

        if ($file->size() > $maxBytes) {
            return UploadValidationResult::invalid(sprintf(
                'Il file "%s" pesa %s: il limite e %s.',
                $file->sanitizedClientName(),
                $this->formatBytes($file->size()),
                $this->formatBytes($maxBytes),
            ));
        }

        // Il MIME dichiarato dal browser non viene mai usato per decidere:
        // qui contano solo i primi byte del file.
        $detectedMime = $file->detectedMimeType();

        if ($detectedMime === null) {
            return UploadValidationResult::invalid(sprintf(
                'Non e stato possibile riconoscere il tipo del file "%s".',
                $file->sanitizedClientName(),
            ));
        }

        /** @var array<string, string> $allowed */
        $allowed = $this->config->array('image.allowed_mime_types');

        if (! isset($allowed[$detectedMime])) {
            return UploadValidationResult::invalid(sprintf(
                'Il file "%s" non e un formato immagine ammesso. Formati accettati: %s.',
                $file->sanitizedClientName(),
                implode(', ', array_map('strtoupper', array_unique(array_values($allowed)))),
            ));
        }

        // Ultima verifica: il file deve essere decodificabile come immagine.
        // Intercetta gli archivi rinominati e i file troncati a meta upload.
        $dimensions = @getimagesize($file->path());

        if ($dimensions === false || (int) $dimensions[0] < 1 || (int) $dimensions[1] < 1) {
            return UploadValidationResult::invalid(sprintf(
                'Il file "%s" risulta danneggiato o non leggibile.',
                $file->sanitizedClientName(),
            ));
        }

        [$width, $height] = $dimensions;

        // Difesa dalle "decompression bomb": immagini di dimensioni modeste su
        // disco ma enormi una volta decodificate in memoria.
        $megapixels = ($width * $height) / 1_000_000;

        if ($megapixels > 80) {
            return UploadValidationResult::invalid(sprintf(
                'Il file "%s" ha una risoluzione eccessiva (%d x %d pixel).',
                $file->sanitizedClientName(),
                $width,
                $height,
            ));
        }

        return UploadValidationResult::valid(
            extension: $allowed[$detectedMime],
            mimeType: $detectedMime,
            width: (int) $width,
            height: (int) $height,
        );
    }

    /**
     * Valida un gruppo di file, restituendo per ciascuno l'esito.
     *
     * Un file rifiutato non blocca gli altri: caricando trenta fotografie e
     * normale che una o due abbiano problemi, e ricominciare da capo sarebbe
     * frustrante.
     *
     * @param list<UploadedFile> $files
     * @return list<array{file: UploadedFile, result: UploadValidationResult}>
     */
    public function validateMany(array $files): array
    {
        return array_map(
            fn (UploadedFile $file): array => ['file' => $file, 'result' => $this->validateImage($file)],
            $files,
        );
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
        }

        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
}
