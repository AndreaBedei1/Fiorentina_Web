<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\Product;
use App\Models\ProductVariant;

/** Riga del carrello, con prezzo già calcolato sulla variante scelta. */
final readonly class CartLine
{
    public function __construct(
        public string $key,
        public Product $product,
        public ?ProductVariant $variant,
        public int $quantity,
        public float $unitPrice,
        public float $lineTotal,
    ) {
    }

    public function displayName(): string
    {
        return $this->variant !== null
            ? $this->product->name . ' - ' . $this->variant->label
            : $this->product->name;
    }

    public function imageKey(): ?string
    {
        return $this->product->primaryImage()?->storageKey;
    }
}
