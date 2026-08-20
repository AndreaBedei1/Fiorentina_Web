<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\DTO\SocialPostData;

/**
 * Contratto verso una piattaforma social.
 *
 * Instagram, Facebook e YouTube hanno API molto diverse fra loro: questa
 * interfaccia le riduce all'unica cosa che serve al sito, cioè "dammi gli
 * ultimi contenuti pubblicati".
 */
interface SocialProviderInterface
{
    /** @return list<SocialPostData> */
    public function fetchLatest(int $limit = 6): array;

    /** Identificativo della piattaforma: instagram, facebook, youtube. */
    public function provider(): string;

    /** Indica se token e identificativi necessari sono presenti. */
    public function isConfigured(): bool;
}
