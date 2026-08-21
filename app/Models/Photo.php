<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;
use DateTimeImmutable;

/**
 * Fotografia della galleria.
 *
 * Il modello conosce solo la chiave di archiviazione: la traduzione in URL
 * pubblici e responsabilita di App\Services\Media\MediaPaths, così la
 * convenzione dei nomi resta in un solo punto.
 */
final class Photo
{
    use CastsRowValues;

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_HIDDEN = 'hidden';

    public function __construct(
        public readonly int $id,
        public readonly int $albumId,
        public readonly string $storageKey,
        public readonly string $extension,
        public readonly ?string $originalName,
        public readonly ?string $title,
        public readonly ?string $caption,
        public readonly ?string $altText,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?int $filesize,
        public readonly ?DateTimeImmutable $takenAt,
        public readonly bool $hasWatermark,
        public readonly int $sortOrder,
        public readonly string $status,
        public readonly ?string $albumTitle = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            albumId: self::castInt($row, 'album_id'),
            storageKey: self::castString($row, 'storage_key'),
            extension: self::castString($row, 'extension', 'jpg'),
            originalName: self::castNullableString($row, 'original_name'),
            title: self::castNullableString($row, 'title'),
            caption: self::castNullableString($row, 'caption'),
            altText: self::castNullableString($row, 'alt_text'),
            width: self::castNullableInt($row, 'width'),
            height: self::castNullableInt($row, 'height'),
            filesize: self::castNullableInt($row, 'filesize'),
            takenAt: self::castDate($row, 'taken_at'),
            hasWatermark: self::castBool($row, 'has_watermark'),
            sortOrder: self::castInt($row, 'sort_order'),
            status: self::castString($row, 'status', self::STATUS_PUBLISHED),
            albumTitle: self::castNullableString($row, 'album_title'),
        );
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Testo alternativo effettivo.
     *
     * Nessuna immagine resta senza alt: se l'amministratore non lo compila
     * ricadiamo su didascalia, titolo e infine sul nome dell'album.
     */
    public function alt(): string
    {
        foreach ([$this->altText, $this->caption, $this->title] as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return $this->albumTitle !== null
            ? 'Fotografia dell\'album ' . $this->albumTitle
            : 'Fotografia di Baraonda Fiorentina';
    }

    /** Rapporto d'aspetto usato per riservare lo spazio ed evitare il layout shift. */
    public function aspectRatio(): ?float
    {
        if ($this->width === null || $this->height === null || $this->height === 0) {
            return null;
        }

        return round($this->width / $this->height, 4);
    }

    public function isPortrait(): bool
    {
        return $this->width !== null && $this->height !== null && $this->height > $this->width;
    }
}
