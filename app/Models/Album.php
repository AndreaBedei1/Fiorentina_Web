<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Support\Str;
use App\Models\Concerns\CastsRowValues;
use DateTimeImmutable;

/** Raccolta di fotografie legata a una partita, una trasferta o un evento. */
final class Album
{
    use CastsRowValues;

    /** @var list<string> */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?DateTimeImmutable $eventDate,
        public readonly ?int $year,
        public readonly ?int $coverPhotoId,
        public readonly ?Photo $coverPhoto,
        public readonly int $photosCount,
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
            description: self::castNullableString($row, 'description'),
            eventDate: self::castDate($row, 'event_date'),
            year: self::castNullableInt($row, 'year'),
            coverPhotoId: self::castNullableInt($row, 'cover_photo_id'),
            coverPhoto: $coverPhoto,
            photosCount: self::castInt($row, 'photos_count'),
            createdAt: self::castDate($row, 'created_at') ?? new DateTimeImmutable(),
            updatedAt: self::castDate($row, 'updated_at'),
        );
    }

    /**
     * Il pezzo di indirizzo che identifica l'album.
     *
     * Conta il numero; il titolo lo segue per rendere leggibile il
     * collegamento. Cambiando il titolo cambia la coda, ma il numero resta e
     * il vecchio indirizzo continua a portare qui.
     */
    public function urlKey(): string
    {
        $coda = Str::slug($this->title);

        return $coda === '' ? (string) $this->id : $this->id . '-' . $coda;
    }

    public function photosLabel(): string
    {
        return $this->photosCount === 1 ? '1 foto' : $this->photosCount . ' foto';
    }
}
