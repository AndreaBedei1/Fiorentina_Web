<?php

declare(strict_types=1);

namespace App\Services\Shop;

/** Esito di un'operazione sul carrello. */
final readonly class CartActionResult
{
    private function __construct(
        public bool $successful,
        public string $message,
    ) {
    }

    public static function success(string $message): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    public function failed(): bool
    {
        return ! $this->successful;
    }
}
