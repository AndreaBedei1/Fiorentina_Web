<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\DTO\SocialPostData;
use App\Models\SocialPost;
use DateTimeImmutable;

/**
 * Contenuti social fittizi.
 *
 * Ottenere i token Meta richiede un'app verificata e una revisione: fino ad
 * allora la homepage mostrerebbe una sezione vuota. Con questo fornitore la
 * sezione e completa e valutabile, e i testi sono chiaramente riconoscibili
 * come dimostrativi.
 */
final class MockSocialProvider implements SocialProviderInterface
{
    private const SAMPLES = [
        [
            'caption' => 'Trasferta di Bologna: due pullman al completo. Grazie a tutti quelli che c erano. [contenuto dimostrativo]',
            'type' => 'image',
        ],
        [
            'caption' => 'Coreografia in Curva Fiesole per il derby. Un anno di lavoro per novanta minuti. [contenuto dimostrativo]',
            'type' => 'carousel',
        ],
        [
            'caption' => 'Cena sociale 2026: oltre 120 soci al tavolo. Le foto arrivano in galleria. [contenuto dimostrativo]',
            'type' => 'image',
        ],
        [
            'caption' => 'Il video della trasferta di Torino e online sul nostro canale. [contenuto dimostrativo]',
            'type' => 'video',
        ],
        [
            'caption' => 'Nuove sciarpe disponibili in sede e sul sito. [contenuto dimostrativo]',
            'type' => 'image',
        ],
        [
            'caption' => 'Riunione mensile giovedì alle 21. Si parla di trasferte e tesseramento. [contenuto dimostrativo]',
            'type' => 'text',
        ],
    ];

    public function __construct(private readonly string $provider = SocialPost::PROVIDER_INSTAGRAM)
    {
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** @return list<SocialPostData> */
    public function fetchLatest(int $limit = 6): array
    {
        $posts = [];
        $now = new DateTimeImmutable();

        for ($i = 0; $i < min($limit, count(self::SAMPLES)); $i++) {
            $sample = self::SAMPLES[$i];

            $posts[] = new SocialPostData(
                provider: $this->provider,
                externalId: sprintf('mock-%s-%d', $this->provider, $i + 1),
                // Il collegamento porta al profilo, non a un contenuto inesistente.
                permalink: $this->profileUrl(),
                mediaType: $this->provider === SocialPost::PROVIDER_YOUTUBE ? 'video' : $sample['type'],
                mediaUrl: null,
                thumbnailUrl: null,
                caption: $sample['caption'],
                author: 'Baraonda Fiorentina',
                publishedAt: $now->modify(sprintf('-%d days', $i * 3 + 1)),
            );
        }

        return $posts;
    }

    private function profileUrl(): string
    {
        return match ($this->provider) {
            SocialPost::PROVIDER_FACEBOOK => 'https://www.facebook.com/',
            SocialPost::PROVIDER_YOUTUBE => 'https://www.youtube.com/',
            default => 'https://www.instagram.com/',
        };
    }
}
