<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;

/**
 * Riga d'ordine.
 *
 * Nome, variante e prezzo sono copiati al momento della conferma: se il
 * prodotto viene poi modificato o rimosso, l'ordine storico resta fedele.
 */
final class OrderItem
{
    use CastsRowValues;

    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly ?int $productId,
        public readonly ?int $variantId,
        public readonly string $productName,
        public readonly ?string $productSlug,
        public readonly ?string $variantLabel,
        public readonly ?string $imageKey,
        public readonly float $unitPrice,
        public readonly int $quantity,
        public readonly float $lineTotal,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            orderId: self::castInt($row, 'order_id'),
            productId: self::castNullableInt($row, 'product_id'),
            variantId: self::castNullableInt($row, 'variant_id'),
            productName: self::castString($row, 'product_name'),
            productSlug: self::castNullableString($row, 'product_slug'),
            variantLabel: self::castNullableString($row, 'variant_label'),
            imageKey: self::castNullableString($row, 'image_key'),
            unitPrice: self::castFloat($row, 'unit_price'),
            quantity: self::castInt($row, 'quantity', 1),
            lineTotal: self::castFloat($row, 'line_total'),
        );
    }

    public function displayName(): string
    {
        return $this->variantLabel !== null
            ? $this->productName . ' - ' . $this->variantLabel
            : $this->productName;
    }
}
