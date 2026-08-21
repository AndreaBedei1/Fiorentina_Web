<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\Product;
use App\Models\ProductVariant;

/** Riga del carrello: un prodotto, l'eventuale scelta fatta e il prezzo. */
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
            ? sprintf('%s - taglia %s', $this->product->name, $this->variant->label)
            : $this->product->name;
    }

    public function imageKey(): ?string
    {
        return $this->product->primaryImage()?->storageKey;
    }
}
