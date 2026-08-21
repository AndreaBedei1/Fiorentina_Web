<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;

/**
 * Una taglia disponibile per un prodotto.
 *
 * L'etichetta e una delle taglie ammesse (Product::TAGLIE): l'elenco e
 * chiuso, quindi qui non arriva mai una scritta inventata.
 */
final class ProductVariant
{
    use CastsRowValues;

    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly string $label,
        public readonly int $sortOrder,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            productId: self::castInt($row, 'product_id'),
            label: self::castString($row, 'label'),
            sortOrder: self::castInt($row, 'sort_order'),
        );
    }

}
