<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Support\Str;
use App\Models\Concerns\CastsRowValues;

/** Variante acquistabile di un prodotto (taglia, colore o combinazione). */
final class ProductVariant
{
    use CastsRowValues;

    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly string $label,
        public readonly ?string $size,
        public readonly ?string $color,
        public readonly ?string $sku,
        public readonly float $priceModifier,
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
            size: self::castNullableString($row, 'size'),
            color: self::castNullableString($row, 'color'),
            sku: self::castNullableString($row, 'sku'),
            priceModifier: self::castFloat($row, 'price_modifier'),
            quantity: self::castNullableInt($row, 'quantity'),
            isAvailable: self::castBool($row, 'is_available', true),
            sortOrder: self::castInt($row, 'sort_order'),
        );
    }

    public function priceFor(float $basePrice): float
    {
        return round($basePrice + $this->priceModifier, 2);
    }

    /** Etichetta con la differenza di prezzo, quando presente. */
    public function labelWithPrice(float $basePrice): string
    {
        if (abs($this->priceModifier) < 0.005) {
            return $this->label;
        }

        return sprintf(
            '%s (%s%s)',
            $this->label,
            $this->priceModifier > 0 ? '+' : '-',
            Str::money(abs($this->priceModifier)),
        );
    }

    public function isSoldOut(): bool
    {
        return ! $this->isAvailable || ($this->quantity !== null && $this->quantity <= 0);
    }
}
