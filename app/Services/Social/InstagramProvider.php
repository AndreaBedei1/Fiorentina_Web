<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Core\Support\HttpClient;
use App\DTO\SocialPostData;
use App\Models\SocialPost;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Instagram tramite Graph API (account Business o Creator).
 *
 * Nota per chi manterra il sito: il token va rinnovato periodicamente. La
 * sincronizzazione registra un avviso quando la chiamata fallisce, e il sito
 * continua comunque a mostrare gli ultimi contenuti gia salvati.
 */
final class InstagramProvider implements SocialProviderInterface
{
    private const API_BASE = 'https://graph.instagram.com';

    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
        private readonly string $token,
        private readonly string $userId,
    ) {
    }

    public function provider(): string
    {
        return SocialPost::PROVIDER_INSTAGRAM;
    }

    public function isConfigured(): bool
    {
        return trim($this->token) !== '';
    }

    /** @return list<SocialPostData> */
    public function fetchLatest(int $limit = 8): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $fields = 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,username';
        $target = trim($this->userId) !== '' ? $this->userId : 'me';

        $url = sprintf(
            '%s/%s/media?fields=%s&limit=%d&access_token=%s',
            self::API_BASE,
            rawurlencode($target),
            $fields,
            max(1, min(25, $limit)),
            rawurlencode($this->token),
        );

        $response = $this->http->getJson($url);

        if ($response === null || ! isset($response['data']) || ! is_array($response['data'])) {
            $this->logger->warning('Instagram: risposta non utilizzabile.');

            return [];
        }

        $posts = [];

        foreach ($response['data'] as $item) {
            if (! is_array($item) || ! isset($item['id'], $item['permalink'])) {
                continue;
            }

            $mediaType = strtolower((string) ($item['media_type'] ?? 'IMAGE'));

            $posts[] = new SocialPostData(
                provider: $this->provider(),
                externalId: (string) $item['id'],
                permalink: (string) $item['permalink'],
                mediaType: match ($mediaType) {
                    'video' => 'video',
                    'carousel_album' => 'carousel',
                    default => 'image',
                },
                mediaUrl: isset($item['media_url']) ? (string) $item['media_url'] : null,
                // Per i video Instagram fornisce thumbnail_url; per le immagini
                // la miniatura e la media_url stessa.
                thumbnailUrl: isset($item['thumbnail_url'])
                    ? (string) $item['thumbnail_url']
                    : (isset($item['media_url']) ? (string) $item['media_url'] : null),
                caption: isset($item['caption']) ? (string) $item['caption'] : null,
                author: isset($item['username']) ? (string) $item['username'] : null,
                publishedAt: $this->parseDate($item['timestamp'] ?? null),
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
