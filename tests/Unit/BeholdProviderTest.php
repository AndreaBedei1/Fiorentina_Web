<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Support\HttpClient;
use App\Services\Social\BeholdProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Lettura del feed JSON di Behold.
 *
 * Il provider non e mai stato provato contro un feed vero: serve un account
 * collegato a Instagram, e finche non c'e questi test sono l'unica verifica.
 * Le risposte finte seguono la forma documentata da Behold.
 *
 * La struttura di "sizes" e l'unica parte che la documentazione non fissa: i
 * test coprono entrambe le forme plausibili, perche il provider deve reggerle
 * tutte e due invece di scommettere su una.
 */
final class BeholdProviderTest extends TestCase
{
    private const FEED = 'FeedDiProva123';

    private function provider(?array $risposta, string $feed = self::FEED): BeholdProvider
    {
        $http = new class ($risposta) extends HttpClient {
            /** @param array<string, mixed>|null $risposta */
            public function __construct(private readonly ?array $risposta)
            {
                parent::__construct(new NullLogger());
            }

            public function getJson(string $url, array $headers = []): ?array
            {
                return $this->risposta;
            }
        };

        return new BeholdProvider($http, new NullLogger(), $feed);
    }

    /** @return array<string, mixed> */
    private static function post(array $sovrascritture = []): array
    {
        return array_merge([
            'id' => '17900000000000001',
            'timestamp' => '2026-08-18T19:30:00+0000',
            'permalink' => 'https://www.instagram.com/p/ABC123/',
            'mediaType' => 'IMAGE',
            'mediaUrl' => 'https://scontent.cdninstagram.com/originale.jpg',
            'caption' => 'Coreografia in Curva Fiesole #fiorentina #baraonda',
            'prunedCaption' => 'Coreografia in Curva Fiesole',
            'altText' => 'Bandiere viola in curva',
            'username' => 'gruppobaraondafiorentina',
            'sizes' => [
                'small' => ['mediaUrl' => 'https://feeds.behold.so/piccola.jpg'],
                'medium' => ['mediaUrl' => 'https://feeds.behold.so/media.jpg'],
                'large' => ['mediaUrl' => 'https://feeds.behold.so/grande.jpg'],
            ],
        ], $sovrascritture);
    }

    #[Test]
    public function legge_un_post_dal_feed(): void
    {
        $provider = $this->provider(['posts' => [self::post()]]);

        $post = $provider->fetchLatest(6);

        self::assertCount(1, $post);
        self::assertSame('instagram', $post[0]->provider);
        self::assertSame('17900000000000001', $post[0]->externalId);
        self::assertSame('https://www.instagram.com/p/ABC123/', $post[0]->permalink);
        self::assertSame('image', $post[0]->mediaType);
        self::assertSame('gruppobaraondafiorentina', $post[0]->author);
        self::assertSame('2026-08-18', $post[0]->publishedAt?->format('Y-m-d'));
    }

    #[Test]
    public function preferisce_la_didascalia_senza_hashtag(): void
    {
        // Su una card di tre righe la coda di hashtag mangia tutto lo spazio
        // utile: Behold fornisce gia la versione ripulita.
        self::assertSame(
            'Coreografia in Curva Fiesole',
            $this->provider(['posts' => [self::post()]])->fetchLatest(1)[0]->caption,
        );
    }

    #[Test]
    public function senza_didascalia_ripulita_usa_quella_intera(): void
    {
        $senza = self::post();
        unset($senza['prunedCaption']);

        self::assertSame(
            'Coreografia in Curva Fiesole #fiorentina #baraonda',
            $this->provider(['posts' => [$senza]])->fetchLatest(1)[0]->caption,
        );
    }

    #[Test]
    public function sceglie_la_misura_media_dell_immagine(): void
    {
        // "full" sarebbe l'originale a piena risoluzione, che il sito
        // ridurrebbe comunque: scaricarlo sarebbe banda sprecata.
        self::assertSame(
            'https://feeds.behold.so/media.jpg',
            $this->provider(['posts' => [self::post()]])->fetchLatest(1)[0]->thumbnailUrl,
        );
    }

    #[Test]
    public function accetta_le_misure_anche_come_indirizzo_diretto(): void
    {
        // La documentazione non fissa la forma di "sizes": il provider deve
        // reggere sia l'oggetto sia la stringa.
        $diretto = self::post(['sizes' => ['medium' => 'https://feeds.behold.so/diretta.jpg']]);

        self::assertSame(
            'https://feeds.behold.so/diretta.jpg',
            $this->provider(['posts' => [$diretto]])->fetchLatest(1)[0]->thumbnailUrl,
        );
    }

    #[Test]
    public function per_i_video_usa_la_miniatura_e_non_il_filmato(): void
    {
        $video = self::post([
            'mediaType' => 'VIDEO',
            'mediaUrl' => 'https://scontent.cdninstagram.com/filmato.mp4',
            'thumbnailUrl' => 'https://scontent.cdninstagram.com/fermo-immagine.jpg',
        ]);

        $letto = $this->provider(['posts' => [$video]])->fetchLatest(1)[0];

        self::assertSame('video', $letto->mediaType);
        self::assertSame('https://scontent.cdninstagram.com/fermo-immagine.jpg', $letto->thumbnailUrl);
    }

    #[Test]
    public function riconosce_i_caroselli(): void
    {
        $carosello = self::post(['mediaType' => 'CAROUSEL_ALBUM']);

        self::assertSame('carousel', $this->provider(['posts' => [$carosello]])->fetchLatest(1)[0]->mediaType);
    }

    #[Test]
    public function senza_indirizzo_del_post_la_voce_viene_saltata(): void
    {
        $rotto = self::post(['permalink' => '']);

        // Una card che non porta da nessuna parte e peggio di una card in meno.
        self::assertSame([], $this->provider(['posts' => [$rotto]])->fetchLatest(5));
    }

    #[Test]
    public function rispetta_il_numero_richiesto(): void
    {
        $molti = [];

        foreach (range(1, 12) as $i) {
            $molti[] = self::post(['id' => 'post-' . $i, 'permalink' => 'https://www.instagram.com/p/P' . $i . '/']);
        }

        self::assertCount(2, $this->provider(['posts' => $molti])->fetchLatest(2));
    }

    #[Test]
    public function un_feed_irraggiungibile_non_produce_post(): void
    {
        // Se Behold non risponde, chi chiama conserva i contenuti gia salvati:
        // la sezione non deve svuotarsi per un disservizio altrui.
        self::assertSame([], $this->provider(null)->fetchLatest(5));
    }

    #[Test]
    public function un_identificativo_errato_non_produce_post(): void
    {
        // Un feed inesistente risponde con qualcosa che non contiene "posts".
        self::assertSame([], $this->provider(['error' => 'Not found'])->fetchLatest(5));
    }

    #[Test]
    public function senza_identificativo_non_e_configurato(): void
    {
        $provider = $this->provider(['posts' => [self::post()]], feed: '');

        self::assertFalse($provider->isConfigured());
        self::assertSame([], $provider->fetchLatest(5));
    }
}
