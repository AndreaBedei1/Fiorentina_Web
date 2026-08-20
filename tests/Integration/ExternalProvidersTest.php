<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\SocialPost;
use App\Repositories\FootballMatchRepository;
use App\Repositories\SocialPostRepository;
use App\Services\Football\FootballService;
use App\Services\Football\MockFootballProvider;
use App\Services\Social\MockSocialProvider;
use App\Services\Social\SocialService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IntegrationTestCase;

/**
 * Fornitori esterni in modalita dimostrativa.
 *
 * Verifichiamo che il sito sia completamente funzionante senza alcuna chiave
 * API: e la condizione in cui verra consegnato, e in cui potrebbe restare a
 * lungo se il gruppo decidesse di non sottoscrivere un servizio a pagamento.
 */
final class ExternalProvidersTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRequest();
    }

    #[Test]
    public function il_fornitore_dimostrativo_e_sempre_utilizzabile(): void
    {
        $provider = new MockFootballProvider();

        $this->assertTrue($provider->isConfigured());
        $this->assertSame('mock', $provider->name());
        $this->assertNotEmpty($provider->fetchUpcomingMatches(5));
        $this->assertNotEmpty($provider->fetchRecentResults(5));
    }

    #[Test]
    public function senza_chiave_api_si_ricade_sul_fornitore_dimostrativo(): void
    {
        $this->assertSame('mock', self::app()->get(FootballService::class)->providerName());
    }

    #[Test]
    public function la_sincronizzazione_calcio_popola_il_database(): void
    {
        $report = self::app()->get(FootballService::class)->sync();

        $this->assertGreaterThan(0, $report->upcomingCount);
        $this->assertGreaterThan(0, $report->resultsCount);

        $repository = self::app()->get(FootballMatchRepository::class);

        $this->assertGreaterThan(0, $repository->count());
        $this->assertNotNull($repository->nextMatch());
    }

    #[Test]
    public function la_sincronizzazione_e_idempotente(): void
    {
        $service = self::app()->get(FootballService::class);
        $repository = self::app()->get(FootballMatchRepository::class);

        $service->sync();
        $dopoPrima = $repository->count();

        $service->sync();
        $dopoSeconda = $repository->count();

        // Rieseguire il cron non deve duplicare le partite.
        $this->assertSame($dopoPrima, $dopoSeconda);
    }

    #[Test]
    public function la_partita_riconosce_casa_e_trasferta(): void
    {
        self::app()->get(FootballService::class)->sync();

        $matches = self::app()->get(FootballMatchRepository::class)->upcoming(10);

        $this->assertNotEmpty($matches);

        foreach ($matches as $match) {
            $squadraCasa = mb_strtolower($match->homeTeam) === 'fiorentina';

            $this->assertSame($squadraCasa, $match->isHome);
            $this->assertNotSame('Fiorentina', $match->opponent);
        }
    }

    #[Test]
    public function una_partita_inserita_a_mano_non_viene_sovrascritta(): void
    {
        $repository = self::app()->get(FootballMatchRepository::class);

        $id = $repository->createManual([
            'competition' => 'Amichevole',
            'home_team' => 'Fiorentina',
            'away_team' => 'Squadra Locale',
            'is_home' => 1,
            'opponent' => 'Squadra Locale',
            'kickoff_at' => date('Y-m-d H:i:s', strtotime('+10 days')),
            'status' => 'scheduled',
        ]);

        $prima = $repository->find($id);

        self::app()->get(FootballService::class)->sync();

        $dopo = $repository->find($id);

        $this->assertSame($prima?->competition, $dopo?->competition);
        $this->assertSame('Squadra Locale', $dopo?->awayTeam);
        $this->assertTrue($dopo?->isManual);
    }

    #[Test]
    public function i_contenuti_social_dimostrativi_vengono_salvati(): void
    {
        $report = self::app()->get(SocialService::class)->sync();

        $this->assertGreaterThan(0, $report->total());

        $posts = self::app()->get(SocialPostRepository::class)->latest(10);

        $this->assertNotEmpty($posts);

        foreach ($posts as $post) {
            // Ogni contenuto deve avere un collegamento valido e un testo
            // alternativo: nessun riquadro muto in homepage.
            $this->assertNotSame('', $post->permalink);
            $this->assertNotSame('', $post->alt());
        }
    }

    #[Test]
    public function la_sincronizzazione_social_non_duplica_i_contenuti(): void
    {
        $service = self::app()->get(SocialService::class);
        $repository = self::app()->get(SocialPostRepository::class);

        $service->sync();
        $prima = $repository->count();

        $service->sync();

        $this->assertSame($prima, $repository->count());
    }

    #[Test]
    public function i_contenuti_esistenti_sopravvivono_a_una_sincronizzazione_a_vuoto(): void
    {
        $repository = self::app()->get(SocialPostRepository::class);

        $repository->upsert([
            'provider' => SocialPost::PROVIDER_INSTAGRAM,
            'external_id' => 'contenuto-storico',
            'permalink' => 'https://www.instagram.com/p/storico',
            'media_type' => 'image',
            'caption' => 'Contenuto gia salvato',
        ]);

        // La sincronizzazione aggiunge, non azzera: se domani le API Meta non
        // rispondessero, la homepage continuerebbe a mostrare qualcosa.
        self::app()->get(SocialService::class)->sync();

        $this->assertSame(1, (int) $this->db->scalar(
            'SELECT COUNT(*) FROM social_posts WHERE external_id = ?',
            ['contenuto-storico'],
        ));
    }

    #[Test]
    public function il_fornitore_social_dimostrativo_copre_le_tre_piattaforme(): void
    {
        foreach ([SocialPost::PROVIDER_INSTAGRAM, SocialPost::PROVIDER_FACEBOOK, SocialPost::PROVIDER_YOUTUBE] as $platform) {
            $provider = new MockSocialProvider($platform);

            $this->assertTrue($provider->isConfigured());
            $this->assertSame($platform, $provider->provider());
            $this->assertNotEmpty($provider->fetchLatest(3));
        }
    }

    #[Test]
    public function la_sincronizzazione_rimuove_le_partite_di_un_altro_fornitore(): void
    {
        $repository = self::app()->get(FootballMatchRepository::class);

        // Una partita lasciata da un fornitore diverso da quello attivo:
        // e la situazione che si crea passando dai dati dimostrativi a quelli
        // veri, e che riempiva il calendario di partite meta vere e meta
        // inventate senza che si potesse distinguerle.
        $repository->upsert([
            'provider' => 'un-altro-fornitore',
            'external_id' => 'vecchia-1',
            'competition' => 'Serie A',
            'home_team' => 'Fiorentina',
            'away_team' => 'Squadra Immaginaria',
            'is_home' => 1,
            'opponent' => 'Squadra Immaginaria',
            'kickoff_at' => date('Y-m-d H:i:s', strtotime('+20 days')),
            'status' => 'scheduled',
            'is_manual' => 0,
        ]);

        $inserite = $repository->count();

        self::app()->get(FootballService::class)->sync();

        $rimaste = array_filter(
            $repository->upcoming(50),
            static fn ($partita): bool => $partita->awayTeam === 'Squadra Immaginaria',
        );

        $this->assertGreaterThan(0, $inserite);
        $this->assertSame([], $rimaste, 'Le partite di un fornitore superato devono sparire.');
    }

    #[Test]
    public function le_partite_inserite_a_mano_sopravvivono_al_cambio_di_fornitore(): void
    {
        $repository = self::app()->get(FootballMatchRepository::class);

        $id = $repository->createManual([
            'competition' => 'Amichevole',
            'home_team' => 'Fiorentina',
            'away_team' => 'Scritta A Mano',
            'is_home' => 1,
            'opponent' => 'Scritta A Mano',
            'kickoff_at' => date('Y-m-d H:i:s', strtotime('+15 days')),
            'status' => 'scheduled',
        ]);

        self::app()->get(FootballService::class)->sync();

        // La pulizia guarda il fornitore, ma non deve mai toccare cio che ha
        // scritto una persona: quello non e un dato scaduto.
        $this->assertNotNull($repository->find($id));
        $this->assertSame('Scritta A Mano', $repository->find($id)?->awayTeam);
    }
}
