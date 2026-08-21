<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Support\Str;
use App\Models\Concerns\CastsRowValues;
use DateTimeImmutable;

/** Appuntamento del gruppo: trasferta, riunione, cena, raduno, festa. */
final class Event
{
    use CastsRowValues;

    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $shortDescription,
        public readonly ?string $description,
        public readonly ?int $categoryId,
        public readonly ?EventCategory $category,
        public readonly DateTimeImmutable $startsAt,
        public readonly ?DateTimeImmutable $endsAt,
        public readonly ?string $locationName,
        public readonly ?string $address,
        public readonly ?string $city,
        public readonly ?string $meetingPoint,
        public readonly ?DateTimeImmutable $meetingAt,
        public readonly ?string $imageKey,
        public readonly ?string $imageAlt,
        public readonly ?float $cost,
        public readonly ?string $costNote,
        public readonly ?string $info,
        public readonly ?string $contactInfo,
        public readonly bool $limitedSeats,
        public readonly ?int $seats,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, ?EventCategory $category = null): self
    {
        if ($category === null && isset($row['category_name'])) {
            $category = EventCategory::fromRow([
                'id' => $row['category_id'] ?? 0,
                'name' => $row['category_name'],
                'slug' => $row['category_slug'] ?? '',
                'icon' => $row['category_icon'] ?? 'calendar',
                'color' => $row['category_color'] ?? 'viola',
            ]);
        }

        return new self(
            id: self::castInt($row, 'id'),
            title: self::castString($row, 'title'),
            shortDescription: self::castNullableString($row, 'short_description'),
            description: self::castNullableString($row, 'description'),
            categoryId: self::castNullableInt($row, 'category_id'),
            category: $category,
            startsAt: self::castDate($row, 'starts_at') ?? new DateTimeImmutable(),
            endsAt: self::castDate($row, 'ends_at'),
            locationName: self::castNullableString($row, 'location_name'),
            address: self::castNullableString($row, 'address'),
            city: self::castNullableString($row, 'city'),
            meetingPoint: self::castNullableString($row, 'meeting_point'),
            meetingAt: self::castDate($row, 'meeting_at'),
            imageKey: self::castNullableString($row, 'image_key'),
            imageAlt: self::castNullableString($row, 'image_alt'),
            cost: self::castNullableFloat($row, 'cost'),
            costNote: self::castNullableString($row, 'cost_note'),
            info: self::castNullableString($row, 'info'),
            contactInfo: self::castNullableString($row, 'contact_info'),
            limitedSeats: self::castBool($row, 'limited_seats'),
            seats: self::castNullableInt($row, 'seats'),
            metaTitle: self::castNullableString($row, 'meta_title'),
            metaDescription: self::castNullableString($row, 'meta_description'),
            createdAt: self::castDate($row, 'created_at') ?? new DateTimeImmutable(),
            updatedAt: self::castDate($row, 'updated_at'),
        );
    }

    public function isPast(): bool
    {
        return ($this->endsAt ?? $this->startsAt) < new DateTimeImmutable();
    }

    public function isUpcoming(): bool
    {
        return ! $this->isPast();
    }

    /** Luogo leggibile in una riga: "Stadio Franchi, Firenze". */
    /**
     * Il pezzo di indirizzo che identifica l'evento.
     *
     * Conta il numero; il titolo lo segue solo per rendere leggibile il
     * collegamento quando finisce in una chat. Cambiando il titolo cambia la
     * coda, ma il numero resta e il vecchio indirizzo porta ancora qui.
     */
    public function urlKey(): string
    {
        $coda = Str::slug($this->title);

        return $coda === '' ? (string) $this->id : $this->id . '-' . $coda;
    }

    public function locationLine(): string
    {
        $parts = array_filter([$this->locationName, $this->city]);

        return implode(', ', $parts);
    }

    public function summary(int $limit = 160): string
    {
        if ($this->shortDescription !== null && trim($this->shortDescription) !== '') {
            return Str::truncate($this->shortDescription, $limit);
        }

        return Str::excerpt($this->description ?? '', $limit);
    }

    public function costLabel(): string
    {
        if ($this->cost === null) {
            return $this->costNote ?? 'Gratuito';
        }

        return Str::money($this->cost) . ($this->costNote !== null ? ' - ' . $this->costNote : '');
    }

    public function seoTitle(): string
    {
        return $this->metaTitle ?? $this->title;
    }

    public function seoDescription(): string
    {
        return $this->metaDescription ?? $this->summary(160);
    }
}
