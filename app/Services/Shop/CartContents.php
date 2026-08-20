<?php

declare(strict_types=1);

namespace App\Services\Shop;

/** Contenuto del carrello con i totali già calcolati. */
final readonly class CartContents
{
    /**
     * @param list<CartLine> $lines
     * @param list<string>   $notices Avvisi su articoli rimossi automaticamente.
     */
    public function __construct(
        public array $lines,
        public array $notices,
        public float $subtotal,
        public int $totalQuantity,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->lines !== [];
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }
}
