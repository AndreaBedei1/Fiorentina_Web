<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Application;
use App\Core\Config;
use App\Services\SettingsService;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Psr\Log\LoggerInterface;

/**
 * Filigrana con il logo del gruppo sulle fotografie della galleria.
 *
 * Obiettivo dichiarato: scoraggiare il riuso non autorizzato, non rovinare la
 * fotografia. Per questo la filigrana e semitrasparente, proporzionale al lato
 * lungo dell'immagine e non viene applicata alle miniature, dove sarebbe solo
 * una macchia che copre il soggetto.
 *
 * L'originale non viene mai modificato: la filigrana esiste solo sulle copie
 * pubbliche, quindi le impostazioni restano cambiabili in qualsiasi momento.
 */
final class WatermarkService
{
    /** Misure su cui la filigrana viene applicata. Le miniature restano pulite. */
    private const WATERMARKED_SIZES = [MediaPaths::SIZE_MEDIUM, MediaPaths::SIZE_LARGE];

    private ?string $resolvedFile = null;

    private bool $fileResolved = false;

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->bool('watermark_enabled', $this->config->bool('image.watermark.enabled', true));
    }

    /** La filigrana e utilizzabile solo se il file del logo esiste davvero. */
    public function isAvailable(): bool
    {
        return $this->isEnabled() && $this->watermarkFile() !== null;
    }

    /**
     * Percorso del file di filigrana.
     *
     * Il progetto viene consegnato con un segnaposto; sostituendolo con il logo
     * definitivo in `resources/images/watermark.png` tutto continua a funzionare
     * senza modifiche al codice.
     */
    public function watermarkFile(): ?string
    {
        if ($this->fileResolved) {
            return $this->resolvedFile;
        }

        $this->fileResolved = true;

        $configured = $this->config->string('image.watermark.file', 'images/watermark.png');

        foreach ([
            $this->app->resourcePath($configured),
            $this->app->resourcePath('images/watermark.png'),
        ] as $candidate) {
            if (is_file($candidate)) {
                return $this->resolvedFile = $candidate;
            }
        }

        return $this->resolvedFile = null;
    }

    /**
     * Applica la filigrana a un'immagine già ridimensionata.
     *
     * @param string $sizeName Misura corrente: determina se applicarla o no.
     */
    public function applyTo(ImageInterface $image, string $sizeName): ImageInterface
    {
        if (! in_array($sizeName, self::WATERMARKED_SIZES, true) || ! $this->isAvailable()) {
            return $image;
        }

        $minWidth = $this->config->int('image.watermark.min_width', 600);

        if ($image->width() < $minWidth) {
            return $image;
        }

        $file = $this->watermarkFile();

        if ($file === null) {
            return $image;
        }

        try {
            $manager = new ImageManager($image->driver());
            $mark = $manager->read($file);

            // Larghezza proporzionale al lato lungo: su una foto da 2000 px la
            // filigrana resta leggibile, su una da 800 non diventa invadente.
            $longestSide = max($image->width(), $image->height());
            $scalePercent = max(5, min(50, $this->settings->int('watermark_scale', $this->config->int('image.watermark.scale', 18))));
            $targetWidth = (int) round($longestSide * $scalePercent / 100);

            $mark->scaleDown($targetWidth, $targetWidth);

            $marginPercent = max(0, min(20, $this->config->int('image.watermark.margin', 3)));
            $margin = (int) round($longestSide * $marginPercent / 100);

            $opacity = max(5, min(100, $this->settings->int('watermark_opacity', $this->config->int('image.watermark.opacity', 38))));
            $position = $this->normalizePosition(
                $this->settings->string('watermark_position', $this->config->string('image.watermark.position', 'bottom-right'))
            );

            if ($position === 'tiled') {
                return $this->applyTiled($image, $mark, $opacity);
            }

            return $image->place($mark, $position, $margin, $margin, $opacity);
        } catch (\Throwable $e) {
            // Una filigrana che non si applica non deve impedire il caricamento
            // della fotografia: registriamo e proseguiamo senza.
            $this->logger->warning('Applicazione della filigrana non riuscita.', ['error' => $e->getMessage()]);

            return $image;
        }
    }

    /**
     * Filigrana ripetuta a scacchiera.
     *
     * Molto più difficile da rimuovere con un ritaglio rispetto a un singolo
     * logo in un angolo, al prezzo di una maggiore invadenza: resta una scelta
     * dell'amministratore.
     */
    private function applyTiled(ImageInterface $image, ImageInterface $mark, int $opacity): ImageInterface
    {
        $stepX = max(1, $mark->width() * 2);
        $stepY = max(1, $mark->height() * 3);

        // Un tetto al numero di ripetizioni evita tempi di elaborazione lunghi
        // su immagini grandi con filigrane molto piccole.
        $maxTiles = 40;
        $tiles = 0;

        for ($y = 0; $y < $image->height() && $tiles < $maxTiles; $y += $stepY) {
            for ($x = 0; $x < $image->width() && $tiles < $maxTiles; $x += $stepX) {
                $image->place($mark, 'top-left', $x, $y, (int) round($opacity * 0.7));
                $tiles++;
            }
        }

        return $image;
    }

    private function normalizePosition(string $position): string
    {
        $allowed = ['bottom-right', 'bottom-left', 'top-right', 'top-left', 'center', 'bottom-center', 'tiled'];

        return in_array($position, $allowed, true) ? $position : 'bottom-right';
    }

    /** @return array<string, string> Posizioni disponibili nel pannello impostazioni. */
    public static function positionOptions(): array
    {
        return [
            'bottom-right' => 'In basso a destra',
            'bottom-left' => 'In basso a sinistra',
            'top-right' => 'In alto a destra',
            'top-left' => 'In alto a sinistra',
            'bottom-center' => 'In basso al centro',
            'center' => 'Al centro',
            'tiled' => 'Ripetuta (più difficile da rimuovere)',
        ];
    }
}
