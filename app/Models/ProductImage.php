<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;

/** Immagine di un prodotto del catalogo. */
final class ProductImage
{
    use CastsRowValues;

    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly string $storageKey,
        public readonly string $extension,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly int $sortOrder,
        public readonly bool $isPrimary,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            productId: self::castInt($row, 'product_id'),
            storageKey: self::castString($row, 'storage_key'),
            extension: self::castString($row, 'extension', 'jpg'),
            width: self::castNullableInt($row, 'width'),
            height: self::castNullableInt($row, 'height'),
            sortOrder: self::castInt($row, 'sort_order'),
            isPrimary: self::castBool($row, 'is_primary'),
        );
    }

    /** Come si annuncia la fotografia a chi non la vede: il nome del prodotto. */
    public function alt(string $productName): string
    {
        return $productName;
    }
}
