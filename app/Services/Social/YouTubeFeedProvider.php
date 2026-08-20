<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Core\Support\HttpClient;
use App\DTO\SocialPostData;
use App\Models\SocialPost;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Video di YouTube letti dal feed pubblico del canale.
 *
 * YouTube pubblica ancora, per ogni canale, un feed Atom accessibile senza
 * chiave e senza autenticazione:
 *
 *     https://www.youtube.com/feeds/videos.xml?channel_id=UC...
 *
 * E l'unico dei tre social che si possa aggiornare da solo senza token da
 * rinnovare: Instagram e Facebook hanno chiuso ogni accesso pubblico. Qui non
 * c'e niente da configurare oltre all'indirizzo del canale, niente che scada e
 * nessun limite di chiamate da tenere d'occhio.
 *
 * Il feed contiene gli ultimi quindici video, che e molto piu di quanto serva:
 * il sito ne mostra due o tre.
 *
 * Rispetto al provider basato su YouTube Data API v3 si perdono le
 * statistiche (visualizzazioni, durata), che il sito non usa. Se un giorno
 * servissero, quella strada resta disponibile con una chiave gratuita.
 */
final class YouTubeFeedProvider implements SocialProviderInterface
{
    private const FEED_URL = 'https://www.youtube.com/feeds/videos.xml?channel_id=%s';

    /** Spazi dei nomi usati dal feed di YouTube. */
    private const NS_YT = 'http://www.youtube.com/xml/schemas/2015';
    private const NS_MEDIA = 'http://search.yahoo.com/mrss/';

    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
        private readonly string $channelId,
    ) {
    }

    public function provider(): string
    {
        return SocialPost::PROVIDER_YOUTUBE;
    }

    public function isConfigured(): bool
    {
        return $this->channelId !== '';
    }

    /** @return list<SocialPostData> */
    public function fetchLatest(int $limit = 6): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $xml = $this->http->get(sprintf(self::FEED_URL, rawurlencode($this->channelId)));

        if ($xml === null) {
            $this->logger->warning('Feed YouTube non raggiungibile.', ['canale' => $this->channelId]);

            return [];
        }

        // Un feed malformato non deve sollevare warning di libxml a schermo:
        // qui interessa solo sapere se si e riusciti a leggerlo.
        $precedente = libxml_use_internal_errors(true);
        $documento = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($precedente);

        if ($documento === false) {
            $this->logger->warning('Feed YouTube illeggibile.', ['canale' => $this->channelId]);

            return [];
        }

        $video = [];

        foreach ($documento->entry ?? [] as $voce) {
            $letto = $this->leggiVoce($voce);

            if ($letto !== null) {
                $video[] = $letto;
            }

            if (count($video) >= max(1, $limit)) {
                break;
            }
        }

        return $video;
    }

    private function leggiVoce(\SimpleXMLElement $voce): ?SocialPostData
    {
        $yt = $voce->children(self::NS_YT);
        $media = $voce->children(self::NS_MEDIA);

        $videoId = trim((string) ($yt->videoId ?? ''));

        if ($videoId === '') {
            return null;
        }

        try {
            $pubblicato = new DateTimeImmutable((string) ($voce->published ?? 'now'));
        } catch (\Exception) {
            $pubblicato = null;
        }

        $miniatura = $this->miniatura($voce, $videoId);

        return new SocialPostData(
            provider: SocialPost::PROVIDER_YOUTUBE,
            externalId: $videoId,
            // L'indirizzo si ricostruisce invece di leggerlo dal feed: quello
            // nel feed a volte porta parametri di tracciamento che non c'e
            // ragione di propagare ai visitatori.
            permalink: 'https://www.youtube.com/watch?v=' . $videoId,
            mediaType: 'video',
            mediaUrl: null,
            thumbnailUrl: $miniatura,
            caption: $this->titolo($voce, $media),
            author: trim((string) ($voce->author->name ?? '')) ?: null,
            publishedAt: $pubblicato,
        );
    }

    /**
     * Indirizzo della miniatura del video.
     *
     * Si legge dal feed con xpath e non navigando l'albero: SimpleXML perde il
     * contesto dello spazio dei nomi scendendo dentro media:group, e
     * $media->group->thumbnail restituisce sempre stringa vuota. Il difetto
     * non si vede finche non si guarda il risultato: le miniature semplicemente
     * non comparivano.
     *
     * Se il feed non la porta, si costruisce: l'indirizzo delle miniature di
     * YouTube e prevedibile dall'identificativo del video.
     */
    private function miniatura(\SimpleXMLElement $voce, string $videoId): string
    {
        $voce->registerXPathNamespace('media', self::NS_MEDIA);
        $trovate = $voce->xpath('media:group/media:thumbnail');

        if (is_array($trovate) && $trovate !== []) {
            $indirizzo = trim((string) $trovate[0]['url']);

            if ($indirizzo !== '') {
                return $indirizzo;
            }
        }

        return sprintf('https://i.ytimg.com/vi/%s/hqdefault.jpg', $videoId);
    }

    private function titolo(\SimpleXMLElement $voce, \SimpleXMLElement $media): ?string
    {
        $titolo = trim((string) ($voce->title ?? ''));

        if ($titolo === '') {
            $titolo = trim((string) ($media->group->title ?? ''));
        }

        return $titolo === '' ? null : $titolo;
    }
}
