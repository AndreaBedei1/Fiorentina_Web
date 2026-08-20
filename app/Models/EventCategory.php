<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;

/**
 * Categoria di evento (trasferta, riunione, cena, raduno, festa...).
 *
 * Icona e colore sono chiavi simboliche, non classi CSS o emoji grezze: il
 * template le traduce in SVG accessibili, e così l'informazione non passa mai
 * dal solo colore.
 */
final class EventCategory
{
    use CastsRowValues;

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly string $icon,
        public readonly string $color,
        public readonly int $sortOrder,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            name: self::castString($row, 'name'),
            slug: self::castString($row, 'slug'),
            description: self::castNullableString($row, 'description'),
            icon: self::castString($row, 'icon', 'calendar'),
            color: self::castString($row, 'color', 'viola'),
            sortOrder: self::castInt($row, 'sort_order'),
        );
    }

    /** Classi Tailwind del badge. Mappa esplicita: Tailwind non compila classi costruite a runtime. */
    public function badgeClasses(): string
    {
        return match ($this->color) {
            'rosso' => 'bg-rosso-100 text-rosso-800 ring-rosso-200',
            'ambra' => 'bg-amber-100 text-amber-900 ring-amber-200',
            'verde' => 'bg-emerald-100 text-emerald-900 ring-emerald-200',
            'blu' => 'bg-sky-100 text-sky-900 ring-sky-200',
            'sabbia' => 'bg-sabbia-100 text-sabbia-800 ring-sabbia-300',
            default => 'bg-viola-100 text-viola-800 ring-viola-200',
        };
    }

    public function dotClasses(): string
    {
        return match ($this->color) {
            'rosso' => 'bg-rosso-600',
            'ambra' => 'bg-amber-500',
            'verde' => 'bg-emerald-600',
            'blu' => 'bg-sky-600',
            'sabbia' => 'bg-sabbia-500',
            default => 'bg-viola-600',
        };
    }
}
