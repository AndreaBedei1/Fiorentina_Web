<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Core\Support\HttpClient;
use App\DTO\SocialPostData;
use App\Models\SocialPost;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Canale YouTube tramite Data API v3.
 *
 * Richiede solo una chiave API (nessun OAuth) perche legge dati pubblici: e
 * l'integrazione piu semplice da attivare fra le tre.
 */
final class YouTubeProvider implements SocialProviderInterface
{
    private const API_BASE = 'https://www.googleapis.com/youtube/v3';

    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $channelId,
    ) {
    }

    public function provider(): string
    {
        return SocialPost::PROVIDER_YOUTUBE;
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '' && trim($this->channelId) !== '';
    }

    /** @return list<SocialPostData> */
    public function fetchLatest(int $limit = 4): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $url = sprintf(
            '%s/search?key=%s&channelId=%s&part=snippet&order=date&type=video&maxResults=%d',
            self::API_BASE,
            rawurlencode($this->apiKey),
            rawurlencode($this->channelId),
            max(1, min(20, $limit)),
        );

        $response = $this->http->getJson($url);

        if ($response === null || ! isset($response['items']) || ! is_array($response['items'])) {
            $this->logger->warning('YouTube: risposta non utilizzabile.');

            return [];
        }

        $posts = [];

        foreach ($response['items'] as $item) {
            $videoId = $item['id']['videoId'] ?? null;
            $snippet = $item['snippet'] ?? null;

            if (! is_string($videoId) || ! is_array($snippet)) {
                continue;
            }

            $thumbnails = $snippet['thumbnails'] ?? [];
            $thumbnail = $thumbnails['high']['url']
                ?? $thumbnails['medium']['url']
                ?? $thumbnails['default']['url']
                ?? null;

            $posts[] = new SocialPostData(
                provider: $this->provider(),
                externalId: $videoId,
                permalink: 'https://www.youtube.com/watch?v=' . $videoId,
                mediaType: 'video',
                mediaUrl: 'https://www.youtube.com/watch?v=' . $videoId,
                thumbnailUrl: is_string($thumbnail) ? $thumbnail : null,
                caption: isset($snippet['title']) ? (string) $snippet['title'] : null,
                author: isset($snippet['channelTitle']) ? (string) $snippet['channelTitle'] : null,
                publishedAt: $this->parseDate($snippet['publishedAt'] ?? null),
            );
        }

        return $posts;
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
