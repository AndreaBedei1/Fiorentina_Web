<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Core\Support\HttpClient;
use App\DTO\SocialPostData;
use App\Models\SocialPost;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/** Pagina Facebook tramite Graph API. */
final class FacebookProvider implements SocialProviderInterface
{
    private const API_BASE = 'https://graph.facebook.com/v21.0';

    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
        private readonly string $token,
        private readonly string $pageId,
    ) {
    }

    public function provider(): string
    {
        return SocialPost::PROVIDER_FACEBOOK;
    }

    public function isConfigured(): bool
    {
        return trim($this->token) !== '' && trim($this->pageId) !== '';
    }

    /** @return list<SocialPostData> */
    public function fetchLatest(int $limit = 6): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $fields = 'id,message,created_time,permalink_url,full_picture,attachments{media_type}';

        $url = sprintf(
            '%s/%s/posts?fields=%s&limit=%d&access_token=%s',
            self::API_BASE,
            rawurlencode($this->pageId),
            rawurlencode($fields),
            max(1, min(25, $limit)),
            rawurlencode($this->token),
        );

        $response = $this->http->getJson($url);

        if ($response === null || ! isset($response['data']) || ! is_array($response['data'])) {
            $this->logger->warning('Facebook: risposta non utilizzabile.');

            return [];
        }

        $posts = [];

        foreach ($response['data'] as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }

            $mediaType = strtolower(
                (string) ($item['attachments']['data'][0]['media_type'] ?? 'photo')
            );

            $posts[] = new SocialPostData(
                provider: $this->provider(),
                externalId: (string) $item['id'],
                permalink: (string) ($item['permalink_url'] ?? 'https://www.facebook.com/' . $this->pageId),
                mediaType: match ($mediaType) {
                    'video' => 'video',
                    'album' => 'carousel',
                    'photo' => 'image',
                    default => isset($item['full_picture']) ? 'image' : 'text',
                },
                mediaUrl: isset($item['full_picture']) ? (string) $item['full_picture'] : null,
                thumbnailUrl: isset($item['full_picture']) ? (string) $item['full_picture'] : null,
                caption: isset($item['message']) ? (string) $item['message'] : null,
                author: 'Baraonda Fiorentina',
                publishedAt: $this->parseDate($item['created_time'] ?? null),
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
