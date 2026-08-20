<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Support\HttpClient;
use App\Services\Football\FootballDataProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Lettura delle risposte di football-data.org.
 *
 * Il fornitore non e mai stato provato contro il servizio vero: la chiave e
 * gratuita ma va richiesta, e questi test sono l'unica verifica possibile nel
 * frattempo. Le risposte finte riproducono la forma documentata, compresi i
 * dettagli che si sbagliano piu facilmente: l'orario in UTC e il punteggio
 * annidato dentro "fullTime".
 */
final class FootballDataProviderTest extends TestCase
{
    private function provider(array $risposte): FootballDataProvider
    {
        $http = new class ($risposte) extends HttpClient {
            /** @param array<string, array<string, mixed>> $risposte */
            public function __construct(private readonly array $risposte)
            {
                parent::__construct(new NullLogger());
            }

            public function getJson(string $url, array $headers = []): ?array
            {
                foreach ($this->risposte as $frammento => $risposta) {
                    if (str_contains($url, $frammento)) {
                        return $risposta;
                    }
                }

                return null;
            }
        };

        return new FootballDataProvider(
            $http,
            new NullLogger(),
            'chiave-finta',
            99,
            'Fiorentina',
            new DateTimeImmutable('2026-08-20 12:00:00'),
        );
    }

    /** @return array<string, mixed> */
    private static function partitaInProgramma(): array
    {
        return [
            'id' => 498001,
            'utcDate' => '2026-08-24T18:45:00Z',
            'status' => 'TIMED',
            'matchday' => 1,
            'stage' => 'REGULAR_SEASON',
            'season' => ['startDate' => '2026-08-22'],
            'competition' => ['id' => 2019, 'name' => 'Serie A', 'code' => 'SA'],
            'homeTeam' => ['id' => 100, 'name' => 'AS Roma', 'shortName' => 'Roma', 'crest' => 'https://crests.football-data.org/100.png'],
            'awayTeam' => ['id' => 99, 'name' => 'ACF Fiorentina', 'shortName' => 'Fiorentina', 'crest' => 'https://crests.football-data.org/99.png'],
            'score' => ['winner' => null, 'fullTime' => ['home' => null, 'away' => null]],
            'venue' => 'Stadio Olimpico',
        ];
    }

    #[Test]
    public function legge_una_partita_in_programma(): void
    {
        $provider = $this->provider(['/teams/99/matches' => ['matches' => [self::partitaInProgramma()]]]);

        $partite = $provider->fetchUpcomingMatches(5);

        self::assertCount(1, $partite);

        $partita = $partite[0];
        self::assertSame('498001', $partita->externalId);
        self::assertSame('Serie A', $partita->competition);
        self::assertSame('SA', $partita->competitionCode);
        self::assertSame('Roma', $partita->homeTeam);
        self::assertSame('Fiorentina', $partita->awayTeam);
        self::assertSame('scheduled', $partita->status);
        self::assertSame('Stadio Olimpico', $partita->venue);
        self::assertSame(2026, $partita->season);
        self::assertNull($partita->homeScore);
    }

    #[Test]
    public function converte_l_orario_dal_fuso_di_greenwich(): void
    {
        $provider = $this->provider(['/teams/99/matches' => ['matches' => [self::partitaInProgramma()]]]);

        $partita = $provider->fetchUpcomingMatches(5)[0];

        // 18:45 UTC in agosto sono le 20:45 in Italia. Senza conversione la
        // partita comparirebbe due ore prima, e nessuno se ne accorgerebbe
        // finche qualcuno non arriva allo stadio a fine primo tempo.
        self::assertSame('2026-08-24 20:45', $partita->kickoffAt->format('Y-m-d H:i'));
        self::assertSame('Europe/Rome', $partita->kickoffAt->getTimezone()->getName());
    }

    #[Test]
    public function numera_la_giornata_di_campionato(): void
    {
        $provider = $this->provider(['/teams/99/matches' => ['matches' => [self::partitaInProgramma()]]]);

        self::assertSame("1\u{00AA} giornata", $provider->fetchUpcomingMatches(5)[0]->roundLabel);
    }

    #[Test]
    public function nelle_coppe_usa_la_fase_al_posto_della_giornata(): void
    {
        $coppa = self::partitaInProgramma();
        $coppa['matchday'] = null;
        $coppa['stage'] = 'QUARTER_FINALS';
        $coppa['competition'] = ['id' => 2018, 'name' => 'Coppa Italia', 'code' => 'CIT'];

        $provider = $this->provider(['/teams/99/matches' => ['matches' => [$coppa]]]);

        self::assertSame('Quarti di finale', $provider->fetchUpcomingMatches(5)[0]->roundLabel);
    }

    #[Test]
    public function legge_il_punteggio_di_una_partita_finita(): void
    {
        $finita = self::partitaInProgramma();
        $finita['status'] = 'FINISHED';
        $finita['score'] = ['winner' => 'AWAY_TEAM', 'fullTime' => ['home' => 1, 'away' => 3]];

        $provider = $this->provider(['/teams/99/matches' => ['matches' => [$finita]]]);

        $partita = $provider->fetchRecentResults(5)[0];

        self::assertSame('finished', $partita->status);
        self::assertSame(1, $partita->homeScore);
        self::assertSame(3, $partita->awayScore);
    }

    #[Test]
    public function i_risultati_partono_dal_piu_recente(): void
    {
        $vecchia = self::partitaInProgramma();
        $vecchia['id'] = 1;
        $vecchia['utcDate'] = '2026-05-10T18:45:00Z';
        $vecchia['status'] = 'FINISHED';

        $recente = self::partitaInProgramma();
        $recente['id'] = 2;
        $recente['utcDate'] = '2026-05-24T18:45:00Z';
        $recente['status'] = 'FINISHED';

        // Il servizio le restituisce dalla piu vecchia: in pagina servono al
        // contrario.
        $provider = $this->provider(['/teams/99/matches' => ['matches' => [$vecchia, $recente]]]);

        $partite = $provider->fetchRecentResults(5);

        self::assertSame('2', $partite[0]->externalId);
        self::assertSame('1', $partite[1]->externalId);
    }

    #[Test]
    public function senza_identificativo_cerca_la_squadra_per_nome(): void
    {
        $http = new class extends HttpClient {
            /** @var list<string> */
            public array $chiamate = [];

            public function __construct()
            {
                parent::__construct(new NullLogger());
            }

            public function getJson(string $url, array $headers = []): ?array
            {
                $this->chiamate[] = $url;

                if (str_contains($url, '/competitions/SA/teams')) {
                    return ['teams' => [
                        ['id' => 108, 'name' => 'FC Internazionale Milano', 'shortName' => 'Inter'],
                        ['id' => 99, 'name' => 'ACF Fiorentina', 'shortName' => 'Fiorentina'],
                    ]];
                }

                return ['matches' => []];
            }
        };

        $provider = new FootballDataProvider($http, new NullLogger(), 'chiave-finta', 0, 'Fiorentina');
        $provider->fetchUpcomingMatches(5);

        self::assertStringContainsString('/competitions/SA/teams', $http->chiamate[0]);
        self::assertStringContainsString('/teams/99/matches', $http->chiamate[1]);
    }

    #[Test]
    public function la_squadra_si_cerca_una_volta_sola(): void
    {
        $http = new class extends HttpClient {
            public int $ricercheSquadra = 0;

            public function __construct()
            {
                parent::__construct(new NullLogger());
            }

            public function getJson(string $url, array $headers = []): ?array
            {
                if (str_contains($url, '/competitions/SA/teams')) {
                    $this->ricercheSquadra++;

                    return ['teams' => [['id' => 99, 'name' => 'ACF Fiorentina', 'shortName' => 'Fiorentina']]];
                }

                return ['matches' => []];
            }
        };

        $provider = new FootballDataProvider($http, new NullLogger(), 'chiave-finta', 0, 'Fiorentina');
        $provider->fetchUpcomingMatches(5);
        $provider->fetchRecentResults(5);

        // Il piano gratuito consente dieci richieste al minuto: sprecarne una
        // per ogni lettura sarebbe uno spreco evitabile.
        self::assertSame(1, $http->ricercheSquadra);
    }

    #[Test]
    public function una_risposta_di_errore_non_produce_partite(): void
    {
        $provider = $this->provider([
            '/teams/99/matches' => ['errorCode' => 403, 'message' => 'The resource you are looking for is restricted.'],
        ]);

        // Chiave errata o piano insufficiente non devono svuotare il
        // calendario: il servizio superiore conserva quello che c'e gia.
        self::assertSame([], $provider->fetchUpcomingMatches(5));
    }

    #[Test]
    public function senza_chiave_non_chiama_nulla(): void
    {
        $provider = new FootballDataProvider(
            new HttpClient(new NullLogger()),
            new NullLogger(),
            '',
        );

        self::assertFalse($provider->isConfigured());
        self::assertSame([], $provider->fetchUpcomingMatches(5));
        self::assertSame([], $provider->fetchRecentResults(5));
    }

    #[Test]
    public function se_le_risorse_della_squadra_sono_negate_ripiega_sul_campionato(): void
    {
        // E lo scenario che non si puo escludere senza una chiave in mano: il
        // piano gratuito comprende la Serie A ma forse non l'elenco partite di
        // una squadra, che spazia anche su coppe non comprese.
        $provider = $this->provider([
            '/teams/99/matches' => ['errorCode' => 403, 'message' => 'The resource you are looking for is restricted.'],
            '/competitions/SA/matches' => ['matches' => [self::partitaInProgramma()]],
        ]);

        $partite = $provider->fetchUpcomingMatches(5);

        self::assertCount(1, $partite);
        self::assertSame('Fiorentina', $partite[0]->awayTeam);
    }

    #[Test]
    public function dal_campionato_tiene_solo_le_partite_della_squadra(): void
    {
        $altrui = self::partitaInProgramma();
        $altrui['id'] = 777;
        $altrui['homeTeam'] = ['id' => 108, 'name' => 'FC Internazionale Milano', 'shortName' => 'Inter'];
        $altrui['awayTeam'] = ['id' => 98, 'name' => 'AC Milan', 'shortName' => 'Milan'];

        $provider = $this->provider([
            '/teams/99/matches' => ['matches' => []],
            '/competitions/SA/matches' => ['matches' => [$altrui, self::partitaInProgramma()]],
        ]);

        $partite = $provider->fetchUpcomingMatches(5);

        // L'elenco del campionato contiene tutte e venti le squadre: senza
        // filtro il calendario del sito si riempirebbe di partite altrui.
        self::assertCount(1, $partite);
        self::assertSame('498001', $partite[0]->externalId);
    }

    #[Test]
    public function limita_la_ricerca_a_una_finestra_di_date(): void
    {
        $http = new class extends HttpClient {
            /** @var list<string> */
            public array $chiamate = [];

            public function __construct()
            {
                parent::__construct(new NullLogger());
            }

            public function getJson(string $url, array $headers = []): ?array
            {
                $this->chiamate[] = $url;

                return ['matches' => []];
            }
        };

        $provider = new FootballDataProvider(
            $http,
            new NullLogger(),
            'chiave-finta',
            99,
            'Fiorentina',
            new DateTimeImmutable('2026-08-20 12:00:00'),
        );

        $provider->fetchUpcomingMatches(5);

        // Senza limiti di data l'elenco del campionato restituirebbe tutte le
        // 380 partite della stagione.
        self::assertStringContainsString('dateFrom=2026-08-20', $http->chiamate[0]);
        self::assertStringContainsString('dateTo=2026-12-18', $http->chiamate[0]);
    }

    #[Test]
    public function i_risultati_guardano_indietro_nel_tempo(): void
    {
        $http = new class extends HttpClient {
            /** @var list<string> */
            public array $chiamate = [];

            public function __construct()
            {
                parent::__construct(new NullLogger());
            }

            public function getJson(string $url, array $headers = []): ?array
            {
                $this->chiamate[] = $url;

                return ['matches' => []];
            }
        };

        $provider = new FootballDataProvider(
            $http,
            new NullLogger(),
            'chiave-finta',
            99,
            'Fiorentina',
            new DateTimeImmutable('2026-08-20 12:00:00'),
        );

        $provider->fetchRecentResults(5);

        self::assertStringContainsString('dateFrom=2026-04-22', $http->chiamate[0]);
        self::assertStringContainsString('dateTo=2026-08-20', $http->chiamate[0]);
        self::assertStringContainsString('status=FINISHED', $http->chiamate[0]);
    }

    #[Test]
    public function distingue_l_orario_confermato_da_quello_ancora_da_fissare(): void
    {
        $conOra = self::partitaInProgramma();          // status TIMED

        $senzaOra = self::partitaInProgramma();
        $senzaOra['id'] = 498002;
        $senzaOra['status'] = 'SCHEDULED';
        // Quando la lega non ha ancora deciso l'ora, il servizio manda
        // mezzanotte come segnaposto. Presa per buona diventa "le 02:00" in
        // Italia: un orario inventato, identico a uno vero per chi legge.
        $senzaOra['utcDate'] = '2026-10-11T00:00:00Z';

        $provider = $this->provider(['/teams/99/matches' => ['matches' => [$conOra, $senzaOra]]]);

        $partite = $provider->fetchUpcomingMatches(5);

        self::assertTrue($partite[0]->timeConfirmed);
        self::assertFalse($partite[1]->timeConfirmed);
    }
}
