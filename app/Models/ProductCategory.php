<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;

/** Categoria del catalogo merchandising (sciarpe, magliette, felpe, gadget...). */
final class ProductCategory
{
    use CastsRowValues;

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly ?string $imageKey,
        public readonly int $sortOrder,
        public readonly string $status,
        public readonly int $productsCount = 0,
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
            imageKey: self::castNullableString($row, 'image_key'),
            sortOrder: self::castInt($row, 'sort_order'),
            status: self::castString($row, 'status', 'active'),
            productsCount: self::castInt($row, 'products_count'),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
