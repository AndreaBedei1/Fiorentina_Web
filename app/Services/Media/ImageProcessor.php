<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Application;
use App\Core\Config;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Psr\Log\LoggerInterface;

/**
 * Elaborazione delle immagini caricate.
 *
 * Pipeline applicata a ogni fotografia:
 *
 *   originale -> orientamento EXIF -> archiviazione privata
 *             -> ridimensionamento -> filigrana -> WebP + JPEG pubblici
 *
 * Il driver e Imagick quando disponibile (e cosi su Aruba), GD altrimenti: il
 * codice chiamante non cambia. WebP e il formato principale perche pesa il
 * 25-35% in meno a parita di resa; il JPEG resta come rete di sicurezza per i
 * pochi browser che non lo supportano.
 */
final class ImageProcessor
{
    private ?ImageManager $manager = null;

    private ?string $activeDriver = null;

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly MediaPaths $paths,
        private readonly WatermarkService $watermark,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Nome del driver effettivamente in uso: mostrato nella dashboard tecnica. */
    public function driverName(): string
    {
        $this->manager();

        return $this->activeDriver ?? 'nessuno';
    }

    public function manager(): ImageManager
    {
        if ($this->manager instanceof ImageManager) {
            return $this->manager;
        }

        $requested = $this->config->string('image.driver', 'auto');

        $useImagick = match ($requested) {
            'imagick' => true,
            'gd' => false,
            default => extension_loaded('imagick'),
        };

        if ($useImagick && ! extension_loaded('imagick')) {
            $this->logger->warning('Driver imagick richiesto ma non disponibile: uso GD.');
            $useImagick = false;
        }

        if (! $useImagick && ! extension_loaded('gd')) {
            throw new \RuntimeException(
                'Nessun driver immagini disponibile: serve l estensione PHP imagick oppure gd.'
            );
        }

        $this->activeDriver = $useImagick ? 'imagick' : 'gd';

        return $this->manager = new ImageManager($useImagick ? new ImagickDriver() : new GdDriver());
    }

    /**
     * Elabora un file appena caricato e produce tutte le versioni.
     *
     * @param string $sourcePath   File temporaneo da elaborare.
     * @param bool   $applyWatermark Filigrana attiva (galleria si, prodotti no).
     */
    public function process(
        string $sourcePath,
        string $collection,
        string $key,
        string $extension,
        bool $applyWatermark = false,
    ): ProcessedImage {
        $manager = $this->manager();

        $image = $manager->read($sourcePath);

        /*
         * L'orientamento EXIF va normalizzato subito: molte fotografie da
         * smartphone sono memorizzate ruotate, con un tag che indica come
         * girarle. Senza questo passaggio finirebbero di lato nella galleria.
         */
        try {
            $image->orient();
        } catch (\Throwable $e) {
            $this->logger->debug('Orientamento EXIF non applicabile.', ['error' => $e->getMessage()]);
        }

        $originalWidth = $image->width();
        $originalHeight = $image->height();

        // Archiviazione dell'originale (gia orientato, eventualmente contenuto
        // entro un limite ragionevole per non saturare lo spazio dell'hosting).
        $maxOriginal = $this->config->int('image.max_original_dimension', 4000);
        $originalPath = $this->paths->originalPath($collection, $key, $extension);
        $this->paths->ensureDirectoryFor($originalPath);

        $originalImage = clone $image;

        if ($originalWidth > $maxOriginal || $originalHeight > $maxOriginal) {
            $originalImage->scaleDown($maxOriginal, $maxOriginal);
        }

        $originalImage->save($originalPath, quality: 92);

        // Versioni pubbliche.
        /** @var array<string, int> $sizes */
        $sizes = $this->config->array('image.sizes');
        $webpQuality = $this->config->int('image.quality.webp', 82);
        $jpegQuality = $this->config->int('image.quality.jpeg', 85);

        $generated = [];

        foreach ($sizes as $sizeName => $targetWidth) {
            $variant = clone $image;
            $variant->scaleDown((int) $targetWidth, (int) $targetWidth);

            if ($applyWatermark) {
                $variant = $this->watermark->applyTo($variant, (string) $sizeName);
            }

            foreach (['webp' => $webpQuality, 'jpg' => $jpegQuality] as $format => $quality) {
                $destination = $this->paths->publicPath($collection, $key, (string) $sizeName, $format);
                $this->paths->ensureDirectoryFor($destination);

                $encoded = $format === 'webp'
                    ? $variant->toWebp($quality)
                    : $variant->toJpeg($quality);

                $encoded->save($destination);
            }

            $generated[(string) $sizeName] = ['width' => $variant->width(), 'height' => $variant->height()];
        }

        return new ProcessedImage(
            key: $key,
            extension: $extension,
            width: $originalWidth,
            height: $originalHeight,
            filesize: is_file($originalPath) ? (int) filesize($originalPath) : 0,
            hasWatermark: $applyWatermark && $this->watermark->isAvailable(),
            variants: $generated,
        );
    }

    /**
     * Rigenera le versioni pubbliche a partire dall'originale archiviato.
     *
     * Serve dopo un cambio di impostazioni della filigrana: senza, le modifiche
     * varrebbero solo per le fotografie caricate successivamente.
     */
    public function regenerate(string $collection, string $key, string $extension, bool $applyWatermark): bool
    {
        $originalPath = $this->paths->originalPath($collection, $key, $extension);

        if (! is_file($originalPath)) {
            $this->logger->warning('Originale non trovato: rigenerazione saltata.', ['key' => $key]);

            return false;
        }

        try {
            $this->process($originalPath, $collection, $key, $extension, $applyWatermark);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Rigenerazione immagine non riuscita.', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Elabora un'immagine gia presente su disco (per esempio una miniatura
     * social scaricata) senza conservarne l'originale.
     */
    public function processSimple(string $sourcePath, string $collection, string $key, int $maxWidth = 800): bool
    {
        try {
            $image = $this->manager()->read($sourcePath);
            $image->scaleDown($maxWidth, $maxWidth);

            $destination = $this->paths->publicPath($collection, $key, MediaPaths::SIZE_MEDIUM, 'webp');
            $this->paths->ensureDirectoryFor($destination);
            $image->toWebp($this->config->int('image.quality.webp', 82))->save($destination);

            $thumb = clone $image;
            $thumb->scaleDown(400, 400);
            $thumbPath = $this->paths->publicPath($collection, $key, MediaPaths::SIZE_THUMB, 'webp');
            $this->paths->ensureDirectoryFor($thumbPath);
            $thumb->toWebp($this->config->int('image.quality.webp', 82))->save($thumbPath);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Elaborazione immagine semplice non riuscita.', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Estrae la data di scatto dai metadati EXIF, quando presente. */
    public function extractTakenAt(string $path): ?string
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        try {
            $exif = @exif_read_data($path);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($exif)) {
            return null;
        }

        foreach (['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'] as $field) {
            $value = $exif[$field] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            // EXIF usa "AAAA:MM:GG HH:MM:SS": i due punti nella data non sono
            // riconosciuti da DateTime, quindi vanno convertiti in trattini.
            $normalized = preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $value) ?? $value;

            try {
                return (new \DateTimeImmutable($normalized))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                continue;
            }
        }

        return null;
    }
}
