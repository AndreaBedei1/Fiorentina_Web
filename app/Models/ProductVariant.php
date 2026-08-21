<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;

/**
 * Una delle scelte disponibili per un prodotto: una taglia, un colore.
 *
 * Come si chiama la scelta lo dice il prodotto (Product::optionName), non la
 * singola riga: un articolo offre una scala sola, e ripeterla qui vorrebbe
 * dire poterla contraddire.
 */
final class ProductVariant
{
    use CastsRowValues;

    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly string $label,
        public readonly ?int $quantity,
        public readonly bool $isAvailable,
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
            quantity: self::castNullableInt($row, 'quantity'),
            isAvailable: self::castBool($row, 'is_available', true),
            sortOrder: self::castInt($row, 'sort_order'),
        );
    }

    public function isSoldOut(): bool
    {
        return ! $this->isAvailable || ($this->quantity !== null && $this->quantity <= 0);
    }
}
