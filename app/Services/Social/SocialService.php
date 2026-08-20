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
use App\Services\SettingsService;
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
 *    riempirebbe di riquadri vuoti. In più il visitatore non contatta i server
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
        private readonly SettingsService $settings,
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

    /**
     * Gli ultimi contenuti di ciascuna piattaforma configurata.
     *
     * Quante per piattaforma lo decide l'amministratore dalle impostazioni:
     * con due, la homepage mostra gli ultimi due post di Instagram e gli
     * ultimi due di Facebook, e quando ne esce uno nuovo prende il posto del
     * piu vecchio da solo.
     *
     * @return list<SocialPost>
     */
    public function latestPerPlatform(int $perPlatform = 2): array
    {
        $piattaforme = [];

        foreach ([SocialPost::PROVIDER_INSTAGRAM, SocialPost::PROVIDER_FACEBOOK, SocialPost::PROVIDER_YOUTUBE] as $piattaforma) {
            // Una piattaforma senza indirizzo configurato non interessa al
            // gruppo: inutile riservarle spazio in homepage.
            if ($this->settings->string('social_' . $piattaforma . '_url') !== '') {
                $piattaforme[] = $piattaforma;
            }
        }

        if ($piattaforme === []) {
            return $this->repository->latest($perPlatform * 2);
        }

        return $this->repository->latestBalanced(max(1, $perPlatform), $piattaforme);
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
            // Anche i contenuti dimostrativi portano al profilo vero, se e
            // stato configurato: chi prova il sito clicca, e finire sulla home
            // di Instagram non aiuta nessuno.
            return [
                new MockSocialProvider(SocialPost::PROVIDER_INSTAGRAM, $this->settings->string('social_instagram_url')),
                new MockSocialProvider(SocialPost::PROVIDER_FACEBOOK, $this->settings->string('social_facebook_url')),
                new MockSocialProvider(SocialPost::PROVIDER_YOUTUBE, $this->settings->string('social_youtube_url')),
            ];
        }

        $providers = [];

        /*
         * Instagram: due strade, e si prende la prima disponibile.
         *
         * Behold viene prima perche e quella che non chiede manutenzione: e
         * loro a tenere vivo il token. Un token Meta scritto nel .env, quando
         * c'e, e una scelta deliberata di chi installa e ha la precedenza solo
         * se Behold non e configurato.
         */
        $beholdFeed = trim($this->settings->string('social_behold_feed_id'));
        $instagramToken = $this->config->string('services.social.instagram.token');

        if ($beholdFeed !== '') {
            $providers[] = new BeholdProvider($this->http, $this->logger, $beholdFeed);
        } elseif (trim($instagramToken) !== '') {
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
        $canaleYoutube = $this->canaleYouTube();

        if (trim($youtubeKey) !== '') {
            $providers[] = new YouTubeProvider(
                $this->http,
                $this->logger,
                $youtubeKey,
                $canaleYoutube !== '' ? $canaleYoutube : $this->config->string('services.social.youtube.channel_id'),
            );
        } elseif ($canaleYoutube !== '') {
            // Senza chiave si legge il feed pubblico del canale: e l'unico
            // dei tre social che non chieda un token da rinnovare.
            $providers[] = new YouTubeFeedProvider($this->http, $this->logger, $canaleYoutube);
        }

        /*
         * Nessun token: non si inventa nulla.
         *
         * Qui prima si ricadeva sui contenuti dimostrativi, con la
         * motivazione che una sezione vuota fosse peggio. Su un sito vero e
         * il contrario: un visitatore che legge il racconto di una trasferta
         * mai avvenuta non ha modo di sapere che e finto, e la sezione vuota
         * semplicemente non compare.
         *
         * Per una dimostrazione con contenuti finti si chiede esplicitamente:
         * SOCIAL_PROVIDER=mock.
         */
        if ($providers === []) {
            $this->logger->warning('Nessun token social configurato: nessun contenuto verra scaricato.');

            return [];
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

    /**
     * Identificativo del canale YouTube.
     *
     * L'amministratore incolla l'indirizzo del canale, che puo essere in tre
     * forme diverse: /channel/UC..., /@nomecanale oppure /c/nomecanale. Solo
     * la prima contiene gia l'identificativo; per le altre va chiesto a
     * YouTube, il che significa scaricare quasi un megabyte di pagina.
     *
     * Per questo il risultato viene memorizzato: la ricerca avviene una volta
     * sola, non a ogni sincronizzazione.
     */
    private function canaleYouTube(): string
    {
        $memorizzato = trim($this->settings->string('social_youtube_channel_id'));

        if ($memorizzato !== '') {
            return $memorizzato;
        }

        $indirizzo = trim($this->settings->string('social_youtube_url'));

        if ($indirizzo === '') {
            return '';
        }

        // Caso facile: l'identificativo e gia nell'indirizzo.
        if (preg_match('#/channel/(UC[A-Za-z0-9_-]{20,})#', $indirizzo, $trovato) === 1) {
            $this->settings->updateMany(['social_youtube_channel_id' => $trovato[1]]);

            return $trovato[1];
        }

        $pagina = $this->http->get($indirizzo);

        if ($pagina === null) {
            $this->logger->warning('Canale YouTube non raggiungibile.', ['indirizzo' => $indirizzo]);

            return '';
        }

        foreach (['#"channelId":"(UC[A-Za-z0-9_-]{20,})"#', '#/channel/(UC[A-Za-z0-9_-]{20,})#'] as $schema) {
            if (preg_match($schema, $pagina, $trovato) === 1) {
                $this->settings->updateMany(['social_youtube_channel_id' => $trovato[1]]);

                $this->logger->info('Canale YouTube risolto.', [
                    'indirizzo' => $indirizzo,
                    'canale' => $trovato[1],
                ]);

                return $trovato[1];
            }
        }

        $this->logger->warning('Identificativo del canale YouTube non trovato nella pagina.', ['indirizzo' => $indirizzo]);

        return '';
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
