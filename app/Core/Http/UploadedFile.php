<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * File caricato via HTTP.
 *
 * Regola non negoziabile: nulla di cio che arriva dal browser e attendibile.
 * `clientName` e `clientMimeType` esistono solo per messaggi di errore e
 * diagnostica; ogni decisione di sicurezza usa `detectedMimeType()`, che legge
 * i magic bytes del file temporaneo.
 */
final class UploadedFile
{
    public function __construct(
        private readonly string $clientName,
        private readonly string $clientMimeType,
        private readonly string $temporaryPath,
        private readonly int $size,
        private readonly int $error,
    ) {
    }

    /**
     * Converte `$_FILES` (che ha due forme diverse per input singoli e multipli)
     * in una struttura uniforme `campo => list<UploadedFile>`.
     *
     * @param array<string, mixed> $files
     * @return array<string, list<self>>
     */
    public static function normalize(array $files): array
    {
        $normalized = [];

        foreach ($files as $field => $data) {
            if (! is_array($data) || ! isset($data['name'])) {
                continue;
            }

            if (is_array($data['name'])) {
                $count = count($data['name']);

                for ($i = 0; $i < $count; $i++) {
                    $normalized[$field][] = new self(
                        clientName: (string) ($data['name'][$i] ?? ''),
                        clientMimeType: (string) ($data['type'][$i] ?? ''),
                        temporaryPath: (string) ($data['tmp_name'][$i] ?? ''),
                        size: (int) ($data['size'][$i] ?? 0),
                        error: (int) ($data['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                    );
                }

                continue;
            }

            $normalized[$field][] = new self(
                clientName: (string) $data['name'],
                clientMimeType: (string) ($data['type'] ?? ''),
                temporaryPath: (string) ($data['tmp_name'] ?? ''),
                size: (int) ($data['size'] ?? 0),
                error: (int) ($data['error'] ?? UPLOAD_ERR_NO_FILE),
            );
        }

        return $normalized;
    }

    /** Costruttore usato dai test per simulare un upload senza passare da PHP. */
    public static function fake(string $path, ?string $clientName = null): self
    {
        return new self(
            clientName: $clientName ?? basename($path),
            clientMimeType: 'application/octet-stream',
            temporaryPath: $path,
            size: is_file($path) ? (int) filesize($path) : 0,
            error: UPLOAD_ERR_OK,
        );
    }

    public function isEmpty(): bool
    {
        return $this->error === UPLOAD_ERR_NO_FILE || ($this->size === 0 && $this->temporaryPath === '');
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->temporaryPath !== '' && is_file($this->temporaryPath);
    }

    public function error(): int
    {
        return $this->error;
    }

    public function errorMessage(): ?string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Il file supera la dimensione massima consentita dal server.',
            UPLOAD_ERR_PARTIAL => 'Il caricamento del file si e interrotto prima del completamento.',
            UPLOAD_ERR_NO_FILE => 'Nessun file selezionato.',
            UPLOAD_ERR_NO_TMP_DIR => 'Cartella temporanea del server non disponibile.',
            UPLOAD_ERR_CANT_WRITE => 'Il server non e riuscito a scrivere il file su disco.',
            UPLOAD_ERR_EXTENSION => 'Il caricamento e stato bloccato da un modulo del server.',
            default => 'Errore sconosciuto durante il caricamento del file.',
        };
    }

    /** Nome dichiarato dal browser: da usare solo per messaggi, mai per costruire path. */
    public function clientName(): string
    {
        return $this->clientName;
    }

    /**
     * Nome originale ripulito, adatto a essere mostrato o salvato come metadato.
     * Non viene mai usato per determinare la posizione su disco.
     */
    public function sanitizedClientName(): string
    {
        $name = basename(str_replace('\\', '/', $this->clientName));
        $name = preg_replace('/[^\p{L}\p{N}\-_. ]+/u', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        return $name === '' ? 'file' : mb_substr($name, 0, 180);
    }

    public function clientMimeType(): string
    {
        return $this->clientMimeType;
    }

    /** Estensione dichiarata, minuscola e senza punto. Non attendibile da sola. */
    public function clientExtension(): string
    {
        return strtolower(pathinfo($this->clientName, PATHINFO_EXTENSION));
    }

    public function size(): int
    {
        return $this->size;
    }

    public function path(): string
    {
        return $this->temporaryPath;
    }

    /** MIME reale, letto dai magic bytes del file. Questa e la fonte autorevole. */
    public function detectedMimeType(): ?string
    {
        if (! is_file($this->temporaryPath)) {
            return null;
        }

        if (! function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        try {
            $mime = finfo_file($finfo, $this->temporaryPath);
        } finally {
            finfo_close($finfo);
        }

        return is_string($mime) && $mime !== '' ? strtolower($mime) : null;
    }

    /** Sposta il file nella destinazione indicata, creando la directory se serve. */
    public function moveTo(string $destination): bool
    {
        $directory = dirname($destination);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            return false;
        }

        // is_uploaded_file() vale solo per upload HTTP reali: nei test usiamo copy().
        if (is_uploaded_file($this->temporaryPath)) {
            return move_uploaded_file($this->temporaryPath, $destination);
        }

        return copy($this->temporaryPath, $destination);
    }
}
