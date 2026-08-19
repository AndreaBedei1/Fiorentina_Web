<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Core\Config;
use App\Core\Support\HttpClient;
use App\DTO\SocialPostData;
use App\Models\SocialPost;
use App\Repositories\SocialPostRepository;
use App\Services\AuditLogger;
use App\Services\Media\ImageProcessor;
use App\Services\Media\MediaPaths;
use Psr\Log\LoggerInterface;

/**
 * Accesso ai contenuti social e loro sincronizzazione.
 *
 *     API Meta/YouTube -> cron -> SocialService -> database -> homepage
 *
 * Due scelte importanti:
 *
 * 1. Le miniature vengono scaricate e archiviate in locale. Gli URL dei CDN di
 *    Meta scadono nel giro di giorni: senza copia locale la homepage si
 *    riempirebbe di riquadri vuoti. In piu il visitatore non contatta i server
 *    di Meta, cosa che ha effetti anche sul fronte cookie e tracciamento.
 *
 * 2. La sincronizzazione non cancella mai i contenuti esistenti. Se l'API non
 *    risponde o restituisce una lista vuota, resta visibile l'ultimo stato buono.
 */
final class SocialService
{
    public function __construct(
        private readonly SocialPostRepository $repository,
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly MediaPaths $paths,
        private readonly ImageProcessor $images,
        private readonly LoggerInterface $logger,
        private readonly AuditLogger $audit,
    ) {
    }

    // -----------------------------------------------------------------------
    //  Lettura (usata dal sito)
    // -----------------------------------------------------------------------

    /**
     * @param list<string> $providers
     * @return list<SocialPost>
     */
    public function latest(int $limit = 6, array $providers = []): array
    {
        return $this->repository->latest($limit, $providers);
    }

    /** URL dell'anteprima: prima la copia locale, poi l'originale remoto. */
    public function thumbnailUrl(SocialPost $post): ?string
    {
        if ($post->localThumbKey !== null && $this->paths->isValidKey($post->localThumbKey)) {
            return $this->paths->url(
                MediaPaths::COLLECTION_SOCIAL,
                $post->localThumbKey,
                MediaPaths::SIZE_MEDIUM,
            );
        }

        return $post->thumbnailUrl ?? $post->mediaUrl;
    }

    // -----------------------------------------------------------------------
    //  Sincronizzazione (usata dal cron)
    // -----------------------------------------------------------------------

    public function sync(): SocialSyncReport
    {
        $report = new SocialSyncReport();

        foreach ($this->providers() as $provider) {
            if (! $provider->isConfigured()) {
                $report->addError(sprintf('Fornitore "%s" non configurato: saltato.', $provider->provider()));

                continue;
            }

            try {
                $limit = $this->limitFor($provider->provider());
                $posts = $provider->fetchLatest($limit);

                if ($posts === []) {
                    $report->addError(sprintf('Fornitore "%s": nessun contenuto restituito.', $provider->provider()));

                    continue;
                }

                foreach ($posts as $post) {
                    $this->store($post, $report);
                }

                $report->record($provider->provider(), count($posts));

                // Conserviamo solo i contenuti recenti: la homepage ne mostra
                // pochi e l'archivio storico non serve a nessuno.
                foreach ($this->repository->trimToLatest($provider->provider(), max(12, $limit * 2)) as $removedKey) {
                    $this->paths->deleteAll(MediaPaths::COLLECTION_SOCIAL, $removedKey);
                }
            } catch (\Throwable $e) {
                $report->addError(sprintf('Fornitore "%s": %s', $provider->provider(), $e->getMessage()));
                $this->logger->error('Sincronizzazione social non riuscita.', [
                    'provider' => $provider->provider(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->audit->logSystem(
            AuditLogger::SYNC_RUN,
            'Sincronizzazione social: ' . $report->summary(),
            ['counts' => $report->counts(), 'errors' => count($report->errors())],
        );

        return $report;
    }

    private function store(SocialPostData $post, SocialSyncReport $report): void
    {
        $localKey = null;

        if ($post->thumbnailUrl !== null && $post->thumbnailUrl !== '') {
            $localKey = $this->downloadThumbnail($post->thumbnailUrl);

            if ($localKey !== null) {
                $report->countThumbnail();
            }
        }

        $this->repository->upsert([
            'provider' => $post->provider,
            'external_id' => $post->externalId,
            'permalink' => $post->permalink,
            'media_type' => $post->mediaType,
            'media_url' => $post->mediaUrl,
            'thumbnail_url' => $post->thumbnailUrl,
            'local_thumb_key' => $localKey,
            'caption' => $post->caption,
            'author' => $post->author,
            'published_at' => $post->publishedAt?->format('Y-m-d H:i:s'),
        ]);
    }

    /** Scarica ed elabora una miniatura. Restituisce la chiave locale o null. */
    private function downloadThumbnail(string $url): ?string
    {
        $key = $this->paths->generateKey();
        $temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'social-' . bin2hex(random_bytes(6));

        try {
            if (! $this->http->download($url, $temporary)) {
                return null;
            }

            // Il file scaricato viene ricodificato: se non fosse un'immagine
            // valida l'elaborazione fallisce e non finisce nulla fra i file
            // pubblici del sito.
            if (! $this->images->processSimple($temporary, MediaPaths::COLLECTION_SOCIAL, $key, 800)) {
                return null;
            }

            return $key;
        } catch (\Throwable $e) {
            $this->logger->debug('Miniatura social non scaricata.', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Fornitori attivi secondo la configurazione.
     *
     * @return list<SocialProviderInterface>
     */
    public function providers(): array
    {
        $mode = $this->config->string('services.social.provider', 'mock');
        $timeout = $this->config->int('services.social.timeout', 10);

        if ($mode === 'mock') {
            return [
                new MockSocialProvider(SocialPost::PROVIDER_INSTAGRAM),
                new MockSocialProvider(SocialPost::PROVIDER_FACEBOOK),
                new MockSocialProvider(SocialPost::PROVIDER_YOUTUBE),
            ];
        }

        $providers = [];

        $instagramToken = $this->config->string('services.social.instagram.token');

        if (trim($instagramToken) !== '') {
            $providers[] = new InstagramProvider(
                $this->http,
                $this->logger,
                $instagramToken,
                $this->config->string('services.social.instagram.user_id'),
            );
        }

        $facebookToken = $this->config->string('services.social.facebook.token');

        if (trim($facebookToken) !== '') {
            $providers[] = new FacebookProvider(
                $this->http,
                $this->logger,
                $facebookToken,
                $this->config->string('services.social.facebook.page_id'),
            );
        }

        $youtubeKey = $this->config->string('services.social.youtube.api_key');

        if (trim($youtubeKey) !== '') {
            $providers[] = new YouTubeProvider(
                $this->http,
                $this->logger,
                $youtubeKey,
                $this->config->string('services.social.youtube.channel_id'),
            );
        }

        // Nessuna credenziale valida: meglio i contenuti dimostrativi che una
        // sezione vuota in homepage.
        if ($providers === []) {
            $this->logger->info('Nessun token social configurato: uso i fornitori dimostrativi.');

            return [new MockSocialProvider(SocialPost::PROVIDER_INSTAGRAM)];
        }

        return $providers;
    }

    private function limitFor(string $provider): int
    {
        return match ($provider) {
            SocialPost::PROVIDER_FACEBOOK => $this->config->int('services.social.facebook.limit', 6),
            SocialPost::PROVIDER_YOUTUBE => $this->config->int('services.social.youtube.limit', 4),
            default => $this->config->int('services.social.instagram.limit', 8),
        };
    }

    public function lastSyncedAt(): ?string
    {
        return $this->repository->lastSyncedAt();
    }

    public function isMockMode(): bool
    {
        return $this->config->string('services.social.provider', 'mock') === 'mock';
    }
}
