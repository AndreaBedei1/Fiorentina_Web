<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Support\HttpClient;
use App\Services\Social\YouTubeFeedProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Lettura del feed pubblico di un canale YouTube.
 *
 * E l'unica fonte social che il sito possa aggiornare da sola: nessuna chiave,
 * nessun token da rinnovare. Vale quindi la pena verificarne la lettura, e in
 * particolare la miniatura, che sta dentro uno spazio dei nomi annidato dove
 * SimpleXML sbaglia da solo se lo si naviga nel modo ovvio.
 */
final class YouTubeFeedProviderTest extends TestCase
{
    private const CANALE = 'UCcNcO3qBE3W9S0zebIPSl7Q';

    private static function feed(string $voci): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015"'
            . ' xmlns:media="http://search.yahoo.com/mrss/"'
            . ' xmlns="http://www.w3.org/2005/Atom">'
            . '<title>Gruppo Baraonda Fiorentina</title>'
            . $voci
            . '</feed>';
    }

    private static function voce(string $id, string $titolo, string $data, bool $conMiniatura = true): string
    {
        $miniatura = $conMiniatura
            ? '<media:thumbnail url="https://i4.ytimg.com/vi/' . $id . '/hqdefault.jpg" width="480" height="360"/>'
            : '';

        return '<entry>'
            . '<yt:videoId>' . $id . '</yt:videoId>'
            . '<title>' . $titolo . '</title>'
            . '<link rel="alternate" href="https://www.youtube.com/watch?v=' . $id . '&amp;feature=share"/>'
            . '<author><name>Gruppo Baraonda Fiorentina</name></author>'
            . '<published>' . $data . '</published>'
            . '<media:group><media:title>' . $titolo . '</media:title>' . $miniatura . '</media:group>'
            . '</entry>';
    }

    private function provider(?string $risposta, string $canale = self::CANALE): YouTubeFeedProvider
    {
        $http = new class ($risposta) extends HttpClient {
            public function __construct(private readonly ?string $risposta)
            {
                parent::__construct(new NullLogger());
            }

            public function get(string $url, array $headers = []): ?string
            {
                return $this->risposta;
            }
        };

        return new YouTubeFeedProvider($http, new NullLogger(), $canale);
    }

    #[Test]
    public function legge_i_video_dal_feed(): void
    {
        $provider = $this->provider(self::feed(
            self::voce('S8Rf5OrL5v8', 'Baraonda Live', '2026-07-15T09:13:04+00:00')
            . self::voce('BEcPyRU5OlM', 'La Voce della Baraonda', '2026-07-15T07:37:28+00:00'),
        ));

        $video = $provider->fetchLatest(6);

        self::assertCount(2, $video);
        self::assertSame('youtube', $video[0]->provider);
        self::assertSame('S8Rf5OrL5v8', $video[0]->externalId);
        self::assertSame('Baraonda Live', $video[0]->caption);
        self::assertSame('video', $video[0]->mediaType);
        self::assertSame('Gruppo Baraonda Fiorentina', $video[0]->author);
        self::assertSame('2026-07-15', $video[0]->publishedAt?->format('Y-m-d'));
    }

    #[Test]
    public function ricostruisce_l_indirizzo_senza_parametri_di_tracciamento(): void
    {
        $provider = $this->provider(self::feed(
            self::voce('S8Rf5OrL5v8', 'Baraonda Live', '2026-07-15T09:13:04+00:00'),
        ));

        // Nel feed il link porta "&feature=share": non c'e ragione di
        // propagarlo ai visitatori del sito.
        self::assertSame(
            'https://www.youtube.com/watch?v=S8Rf5OrL5v8',
            $provider->fetchLatest(1)[0]->permalink,
        );
    }

    #[Test]
    public function legge_la_miniatura_dallo_spazio_dei_nomi_annidato(): void
    {
        $provider = $this->provider(self::feed(
            self::voce('S8Rf5OrL5v8', 'Baraonda Live', '2026-07-15T09:13:04+00:00'),
        ));

        // Navigando l'albero nel modo ovvio ($media->group->thumbnail)
        // SimpleXML perde il contesto e restituisce stringa vuota: le
        // miniature sparivano senza che nulla segnalasse un errore.
        self::assertSame(
            'https://i4.ytimg.com/vi/S8Rf5OrL5v8/hqdefault.jpg',
            $provider->fetchLatest(1)[0]->thumbnailUrl,
        );
    }

    #[Test]
    public function senza_miniatura_nel_feed_la_costruisce(): void
    {
        $provider = $this->provider(self::feed(
            self::voce('S8Rf5OrL5v8', 'Baraonda Live', '2026-07-15T09:13:04+00:00', conMiniatura: false),
        ));

        self::assertSame(
            'https://i.ytimg.com/vi/S8Rf5OrL5v8/hqdefault.jpg',
            $provider->fetchLatest(1)[0]->thumbnailUrl,
        );
    }

    #[Test]
    public function rispetta_il_numero_di_video_richiesto(): void
    {
        $voci = '';

        foreach (range(1, 10) as $i) {
            $voci .= self::voce('video-000' . $i, 'Video ' . $i, '2026-07-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . 'T10:00:00+00:00');
        }

        // Il feed ne porta sempre quindici, il sito ne mostra due o tre.
        self::assertCount(3, $this->provider(self::feed($voci))->fetchLatest(3));
    }

    #[Test]
    public function un_feed_illeggibile_non_produce_video(): void
    {
        self::assertSame([], $this->provider('questo non e XML')->fetchLatest(5));
    }

    #[Test]
    public function un_feed_irraggiungibile_non_produce_video(): void
    {
        // La sincronizzazione non deve svuotare la sezione quando YouTube non
        // risponde: chi la chiama conserva quello che c'e gia.
        self::assertSame([], $this->provider(null)->fetchLatest(5));
    }

    #[Test]
    public function senza_canale_non_e_configurato(): void
    {
        $provider = $this->provider(self::feed(''), canale: '');

        self::assertFalse($provider->isConfigured());
        self::assertSame([], $provider->fetchLatest(5));
    }
}
