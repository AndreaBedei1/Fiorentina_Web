<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\Order;

/** Esito dell'invio di una richiesta d'ordine. */
final readonly class OrderPlacementResult
{
    private function __construct(
        public bool $successful,
        public ?Order $order = null,
        public ?string $error = null,
        public bool $customerNotified = false,
    ) {
    }

    public static function success(Order $order, bool $customerNotified): self
    {
        return new self(true, $order, null, $customerNotified);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }

    public function failed(): bool
    {
        return ! $this->successful;
    }
}
