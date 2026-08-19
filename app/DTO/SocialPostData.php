<?php

declare(strict_types=1);

namespace App\DTO;

use DateTimeImmutable;

/** Contenuto social normalizzato, indipendente dal provider di origine. */
final readonly class SocialPostData
{
    public function __construct(
        public string $provider,
        public string $externalId,
        public string $permalink,
        public string $mediaType = 'image',
        public ?string $mediaUrl = null,
        public ?string $thumbnailUrl = null,
        public ?string $caption = null,
        public ?string $author = null,
        public ?DateTimeImmutable $publishedAt = null,
    ) {
    }
}
