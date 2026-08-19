<?php

declare(strict_types=1);

namespace App\Core\Security;

/** Coppia token-in-chiaro / hash-persistito prodotta da TokenGenerator. */
final readonly class TokenPair
{
    public function __construct(
        public string $plain,
        public string $hash,
    ) {
    }
}
