<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;
use DateTimeImmutable;

/** Raccolta di fotografie legata a una partita, una trasferta o un evento. */
final class Album
{
    use CastsRowValues;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    /** @var list<string> */
    public const CATEGORIES = ['stadio', 'trasferte', 'eventi', 'raduni', 'storico', 'altro'];

    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly ?DateTimeImmutable $eventDate,
        public readonly ?int $year,
        public readonly string $category,
        public readonly ?int $coverPhotoId,
        public readonly ?Photo $coverPhoto,
        public readonly string $status,
        public readonly int $sortOrder,
        public readonly int $photosCount,
        public readonly ?string $metaDescription,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, ?Photo $coverPhoto = null): self
    {
        if ($coverPhoto === null && ! empty($row['cover_storage_key'])) {
            $coverPhoto = Photo::fromRow([
                'id' => $row['cover_photo_id'] ?? 0,
                'album_id' => $row['id'] ?? 0,
                'storage_key' => $row['cover_storage_key'],
                'extension' => $row['cover_extension'] ?? 'jpg',
                'alt_text' => $row['cover_alt_text'] ?? null,
                'width' => $row['cover_width'] ?? null,
                'height' => $row['cover_height'] ?? null,
            ]);
        }

        return new self(
            id: self::castInt($row, 'id'),
            title: self::castString($row, 'title'),
            slug: self::castString($row, 'slug'),
            description: self::castNullableString($row, 'description'),
            eventDate: self::castDate($row, 'event_date'),
            year: self::castNullableInt($row, 'year'),
            category: self::castString($row, 'category', 'altro'),
            coverPhotoId: self::castNullableInt($row, 'cover_photo_id'),
            coverPhoto: $coverPhoto,
            status: self::castString($row, 'status', self::STATUS_DRAFT),
            sortOrder: self::castInt($row, 'sort_order'),
            photosCount: self::castInt($row, 'photos_count'),
            metaDescription: self::castNullableString($row, 'meta_description'),
            createdAt: self::castDate($row, 'created_at') ?? new DateTimeImmutable(),
            updatedAt: self::castDate($row, 'updated_at'),
        );
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PUBLISHED => 'Pubblicato',
            self::STATUS_ARCHIVED => 'Archiviato',
            default => 'Bozza',
        };
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            'stadio' => 'Stadio',
            'trasferte' => 'Trasferte',
            'eventi' => 'Eventi',
            'raduni' => 'Raduni',
            'storico' => 'Archivio storico',
            default => 'Altro',
        };
    }

    public function photosLabel(): string
    {
        return $this->photosCount === 1 ? '1 foto' : $this->photosCount . ' foto';
    }
}
