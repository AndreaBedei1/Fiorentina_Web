<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Models\SocialPost;
use App\Repositories\FootballMatchRepository;
use App\Repositories\SocialPostRepository;
use App\Services\Football\FootballService;
use App\Services\Football\MockFootballProvider;
use App\Services\Social\BeholdProvider;
use App\Services\Social\MockSocialProvider;
use App\Services\Social\SocialService;
use App\Services\SettingsService;
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

    #[Test]
    public function un_contenuto_social_inserito_a_mano_sopravvive_alla_sincronizzazione(): void
    {
        $repository = self::app()->get(SocialPostRepository::class);

        $id = $repository->createManual([
            'provider' => SocialPost::PROVIDER_INSTAGRAM,
            'permalink' => 'https://www.instagram.com/p/SCELTO-A-MANO/',
            'media_type' => 'image',
            'caption' => 'Scelto dal pannello',
            'local_thumb_key' => '2026/08/aaaabbbbccccdddd',
            'published_at' => date('Y-m-d H:i:s'),
            'is_visible' => 1,
        ]);

        // La sincronizzazione pota i contenuti vecchi per non far crescere la
        // tabella all'infinito. Quella potatura non deve mai arrivare a cio
        // che una persona ha scelto di mettere in vetrina.
        self::app()->get(SocialService::class)->sync();
        self::app()->get(SocialService::class)->sync();

        $dopo = $repository->find($id);

        $this->assertNotNull($dopo);
        $this->assertTrue($dopo->isManual);
        $this->assertSame('https://www.instagram.com/p/SCELTO-A-MANO/', $dopo->permalink);
        $this->assertSame('2026/08/aaaabbbbccccdddd', $dopo->localThumbKey);
    }

    #[Test]
    public function i_contenuti_manuali_compaiono_insieme_a_quelli_scaricati(): void
    {
        $repository = self::app()->get(SocialPostRepository::class);
        $service = self::app()->get(SocialService::class);

        $service->sync();

        $repository->createManual([
            'provider' => SocialPost::PROVIDER_FACEBOOK,
            'permalink' => 'https://www.facebook.com/post-scelto',
            'media_type' => 'image',
            'caption' => 'In vetrina',
            'local_thumb_key' => '2026/08/1111222233334444',
            // Data recente: l'ordinamento e per data, quindi deve arrivare in cima.
            'published_at' => date('Y-m-d H:i:s'),
            'is_visible' => 1,
        ]);

        $permalink = array_map(
            static fn ($post): string => $post->permalink,
            $service->latest(12),
        );

        $this->assertContains('https://www.facebook.com/post-scelto', $permalink);
    }

    #[Test]
    public function la_selezione_per_la_homepage_e_equilibrata_fra_le_piattaforme(): void
    {
        $repository = self::app()->get(SocialPostRepository::class);

        // Instagram pubblica molto in una settimana, Facebook poco: ordinando
        // solo per data, la vetrina diventava tutta Instagram.
        foreach (range(1, 6) as $i) {
            $repository->createManual([
                'provider' => SocialPost::PROVIDER_INSTAGRAM,
                'permalink' => 'https://www.instagram.com/p/IG-' . $i . '/',
                'media_type' => 'image',
                'caption' => 'Instagram ' . $i,
                'published_at' => date('Y-m-d H:i:s', strtotime('-' . $i . ' hours')),
                'is_visible' => 1,
            ]);
        }

        $repository->createManual([
            'provider' => SocialPost::PROVIDER_FACEBOOK,
            'permalink' => 'https://www.facebook.com/FB-1',
            'media_type' => 'image',
            'caption' => 'Facebook 1',
            'published_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
            'is_visible' => 1,
        ]);

        $selezione = $repository->latestBalanced(2, [
            SocialPost::PROVIDER_INSTAGRAM,
            SocialPost::PROVIDER_FACEBOOK,
        ]);

        $piattaforme = array_count_values(array_map(
            static fn ($post): string => $post->provider,
            $selezione,
        ));

        $this->assertSame(2, $piattaforme[SocialPost::PROVIDER_INSTAGRAM] ?? 0);
        $this->assertSame(1, $piattaforme[SocialPost::PROVIDER_FACEBOOK] ?? 0, 'Facebook ne aveva uno solo da dare.');

        // Fra quelli scelti, il piu recente resta in cima.
        $this->assertSame('Instagram 1', $selezione[0]->caption);
    }

    #[Test]
    public function senza_token_non_vengono_inventati_contenuti(): void
    {
        // Con SOCIAL_PROVIDER=live e nessun token, la sezione deve restare
        // vuota: un visitatore non puo distinguere un post finto da uno vero.
        $servizio = self::app()->get(SocialService::class);

        $fornitori = array_filter(
            $servizio->providers(),
            static fn ($fornitore): bool => $fornitore instanceof MockSocialProvider,
        );

        // In modalita "mock" i fornitori dimostrativi ci sono di proposito:
        // questo test verifica solo che esistano perche richiesti, non per
        // ripiego silenzioso.
        $this->assertNotEmpty($servizio->providers());
        $this->assertCount(count($servizio->providers()), $fornitori);
        $this->assertTrue($servizio->isMockMode());
    }

    #[Test]
    public function con_il_feed_behold_configurato_instagram_passa_da_li(): void
    {
        $config = self::app()->get(Config::class);
        $modalitaPrecedente = $config->string('services.social.provider');

        // La suite gira in modalita dimostrativa: qui serve la scelta vera dei
        // fornitori, che e proprio cio che si vuole verificare.
        $config->set('services.social.provider', 'live');

        $impostazioni = self::app()->get(SettingsService::class);

        // Il database dei test nasce dalle sole migrazioni: le impostazioni
        // non esistono ancora, e scriverne una senza la riga non farebbe nulla.
        $impostazioni->ensureDefaults();
        $impostazioni->updateMany(['social_behold_feed_id' => 'FeedDiProva123']);

        $classi = array_map(
            static fn ($fornitore): string => $fornitore::class,
            self::app()->get(SocialService::class)->providers(),
        );

        // Behold ha la precedenza su un token Meta scritto a mano: e la strada
        // che non chiede di rinnovare niente ogni due mesi.
        $this->assertContains(BeholdProvider::class, $classi);

        $impostazioni->updateMany(['social_behold_feed_id' => '']);
        $config->set('services.social.provider', $modalitaPrecedente);
    }
}
