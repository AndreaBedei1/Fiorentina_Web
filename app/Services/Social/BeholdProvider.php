<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Core\Support\HttpClient;
use App\DTO\SocialPostData;
use App\Models\SocialPost;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Post di Instagram letti tramite Behold.
 *
 * Instagram non ha piu alcun accesso pubblico: servono un'app Meta, un account
 * professionale e un token da rinnovare ogni sessanta giorni. Behold fa da
 * tramite: si collega l'account una volta sul loro sito, e da li in poi sono
 * loro a tenere vivo il token. Il sito legge un indirizzo che restituisce i
 * post in JSON.
 *
 *     GET https://feeds.behold.so/{ID_FEED}
 *
 * Non serve nessuna chiave nella richiesta: l'identificativo del feed e
 * l'unica cosa da configurare, e non e un segreto (chi usa il widget lo ha
 * scritto nella pagina).
 *
 * Perche il JSON e non il widget: il widget e uno script caricato dai loro
 * server, e la Content-Security-Policy del sito non ammette codice da domini
 * esterni. Leggendo il JSON dal cron, invece, il visitatore non contatta mai
 * Behold e le immagini restano archiviate qui.
 *
 * ATTENZIONE: Behold copre solo Instagram. I post di una pagina Facebook non
 * sono leggibili da nessuna parte senza un token Meta.
 */
final class BeholdProvider implements SocialProviderInterface
{
    private const FEED_URL = 'https://feeds.behold.so/%s';

    /**
     * Ordine di preferenza fra le misure fornite da Behold.
     *
     * "medium" e la misura giusta per una card: "full" sarebbe l'originale a
     * piena risoluzione, che qui verrebbe comunque ridotto.
     */
    private const MISURE = ['medium', 'large', 'small', 'full'];

    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
        private readonly string $feedId,
    ) {
    }

    public function provider(): string
    {
        return SocialPost::PROVIDER_INSTAGRAM;
    }

    public function isConfigured(): bool
    {
        return $this->identificativo() !== '';
    }

    /**
     * L'identificativo del feed, ripulito da quello che ci sta intorno.
     *
     * Behold, alla fine della configurazione, consegna un indirizzo intero:
     * https://feeds.behold.so/AbC123. Chi lo copia incolla quello, ed e la
     * cosa piu naturale del mondo. Prendere l'ultimo pezzo dell'indirizzo
     * costa due righe ed evita un feed che non risponde senza dire perche.
     */
    private function identificativo(): string
    {
        $valore = trim($this->feedId);

        if ($valore === '' || ! str_contains($valore, '/')) {
            return $valore;
        }

        $pezzi = array_values(array_filter(explode('/', parse_url($valore, PHP_URL_PATH) ?? $valore)));

        return $pezzi === [] ? '' : (string) end($pezzi);
    }

    /** @return list<SocialPostData> */
    public function fetchLatest(int $limit = 6): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $risposta = $this->http->getJson(sprintf(self::FEED_URL, rawurlencode($this->identificativo())));

        if ($risposta === null) {
            $this->logger->warning('Feed Behold non raggiungibile.', ['feed' => $this->feedId]);

            return [];
        }

        $post = $risposta['posts'] ?? null;

        if (! is_array($post)) {
            $this->logger->warning('Feed Behold senza post: identificativo errato?', ['feed' => $this->feedId]);

            return [];
        }

        $letti = [];

        foreach ($post as $voce) {
            if (! is_array($voce)) {
                continue;
            }

            $convertito = $this->leggiPost($voce);

            if ($convertito !== null) {
                $letti[] = $convertito;
            }

            if (count($letti) >= max(1, $limit)) {
                break;
            }
        }

        return $letti;
    }

    /** @param array<string, mixed> $voce */
    private function leggiPost(array $voce): ?SocialPostData
    {
        $id = trim((string) ($voce['id'] ?? ''));
        $permalink = trim((string) ($voce['permalink'] ?? ''));

        // Senza indirizzo del post la card non porterebbe da nessuna parte:
        // meglio saltarla che mostrarne una che non fa niente.
        if ($id === '' || $permalink === '') {
            return null;
        }

        try {
            $pubblicato = new DateTimeImmutable((string) ($voce['timestamp'] ?? 'now'));
        } catch (\Exception) {
            $pubblicato = null;
        }

        return new SocialPostData(
            provider: SocialPost::PROVIDER_INSTAGRAM,
            externalId: $id,
            permalink: $permalink,
            mediaType: $this->tipo((string) ($voce['mediaType'] ?? '')),
            mediaUrl: $this->stringaOppureNull($voce['mediaUrl'] ?? null),
            thumbnailUrl: $this->anteprima($voce),
            caption: $this->didascalia($voce),
            author: $this->stringaOppureNull($voce['username'] ?? null),
            publishedAt: $pubblicato,
        );
    }

    /**
     * Indirizzo dell'immagine da archiviare sul sito.
     *
     * @param array<string, mixed> $voce
     */
    private function anteprima(array $voce): ?string
    {
        // Nei video mediaUrl e il filmato: l'immagine sta in thumbnailUrl.
        $miniatura = $this->stringaOppureNull($voce['thumbnailUrl'] ?? null);

        if ($miniatura !== null) {
            return $miniatura;
        }

        $misure = $voce['sizes'] ?? null;

        if (is_array($misure)) {
            foreach (self::MISURE as $nome) {
                $indirizzo = $this->indirizzoDellaMisura($misure[$nome] ?? null);

                if ($indirizzo !== null) {
                    return $indirizzo;
                }
            }
        }

        return $this->stringaOppureNull($voce['mediaUrl'] ?? null);
    }

    /**
     * Una misura puo essere un indirizzo diretto oppure un oggetto che lo
     * contiene: la documentazione non lo fissa, quindi si accettano entrambe
     * le forme invece di scommettere su una.
     */
    private function indirizzoDellaMisura(mixed $misura): ?string
    {
        if (is_string($misura)) {
            return $this->stringaOppureNull($misura);
        }

        if (! is_array($misura)) {
            return null;
        }

        foreach (['mediaUrl', 'url', 'src'] as $chiave) {
            $indirizzo = $this->stringaOppureNull($misura[$chiave] ?? null);

            if ($indirizzo !== null) {
                return $indirizzo;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $voce */
    private function didascalia(array $voce): ?string
    {
        // prunedCaption e la didascalia senza la coda di hashtag: su una card
        // di poche righe gli hashtag occupano tutto lo spazio utile.
        return $this->stringaOppureNull($voce['prunedCaption'] ?? null)
            ?? $this->stringaOppureNull($voce['caption'] ?? null)
            ?? $this->stringaOppureNull($voce['altText'] ?? null);
    }

    private function tipo(string $mediaType): string
    {
        return match (strtoupper($mediaType)) {
            'VIDEO' => 'video',
            'CAROUSEL_ALBUM' => 'carousel',
            default => 'image',
        };
    }

    private function stringaOppureNull(mixed $valore): ?string
    {
        if (! is_string($valore)) {
            return null;
        }

        $pulito = trim($valore);

        return $pulito === '' ? null : $pulito;
    }
}
