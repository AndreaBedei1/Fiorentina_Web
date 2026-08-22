<?php

declare(strict_types=1);

namespace App\Services\Football;

use App\Core\Config;
use App\Core\Support\HttpClient;
use App\DTO\FootballMatchData;
use App\Models\FootballMatch;
use App\Repositories\FootballMatchRepository;
use Psr\Log\LoggerInterface;

/**
 * Punto di accesso unico ai dati calcistici.
 *
 * Il flusso e volutamente asimmetrico:
 *
 *     API -> cron -> FootballService -> database -> frontend
 *
 * Le pagine pubbliche leggono soltanto il database. Nessuna visita alla
 * homepage genera una chiamata esterna: la pagina resta veloce, i limiti di
 * chiamate del fornitore non si esauriscono e un disservizio dell'API non si
 * traduce in un disservizio del sito.
 */
final class FootballService
{
    public function __construct(
        private readonly FootballMatchRepository $repository,
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    // -----------------------------------------------------------------------
    //  Lettura (usata dal sito)
    // -----------------------------------------------------------------------

    public function nextMatch(): ?FootballMatch
    {
        return $this->repository->nextMatch();
    }

    /** @return list<FootballMatch> */
    public function upcomingMatches(int $limit = 5): array
    {
        return $this->repository->upcoming($limit);
    }

    /** @return list<FootballMatch> */
    public function recentResults(int $limit = 5): array
    {
        return $this->repository->recentResults($limit);
    }

    /** @return list<FootballMatch> */
    public function matchesBetween(string $from, string $to): array
    {
        return $this->repository->betweenDates($from, $to);
    }

    // -----------------------------------------------------------------------
    //  Sincronizzazione (usata dal cron)
    // -----------------------------------------------------------------------

    /**
     * Allinea il database al fornitore configurato.
     *
     * @return FootballSyncReport Riepilogo, stampato dallo script di cron.
     */
    public function sync(): FootballSyncReport
    {
        $provider = $this->provider();

        if (! $provider->isConfigured()) {
            $this->logger->warning('Sincronizzazione calcio saltata: fornitore non configurato.', [
                'provider' => $provider->name(),
            ]);

            return new FootballSyncReport($provider->name(), 0, 0, ['Fornitore non configurato.']);
        }

        $errors = [];
        $upcoming = 0;
        $results = 0;

        /*
         * Passando ai dati veri, le partite dimostrative restavano in
         * calendario accanto a quelle autentiche. Un elenco meta vero e meta
         * inventato e peggio di uno dichiaratamente inventato: chi lo legge non
         * ha modo di distinguere.
         *
         * Si puliscono prima di scrivere, non dopo: se la sincronizzazione
         * fallisse a meta, meglio un calendario vuoto che uno finto.
         */
        $rimosse = $this->repository->deleteFromOtherProviders($provider->name());

        if ($rimosse > 0) {
            $this->logger->info('Rimosse partite di un fornitore precedente.', [
                'quante' => $rimosse,
                'fornitore_attivo' => $provider->name(),
            ]);
        }

        try {
            foreach ($provider->fetchUpcomingMatches(20) as $match) {
                $this->store($match, $provider->name());
                $upcoming++;
            }
        } catch (\Throwable $e) {
            $errors[] = 'Prossime partite: ' . $e->getMessage();
            $this->logger->error('Sincronizzazione prossime partite non riuscita.', ['error' => $e->getMessage()]);
        }

        try {
            foreach ($provider->fetchRecentResults(20) as $match) {
                $this->store($match, $provider->name());
                $results++;
            }
        } catch (\Throwable $e) {
            $errors[] = 'Risultati: ' . $e->getMessage();
            $this->logger->error('Sincronizzazione risultati non riuscita.', ['error' => $e->getMessage()]);
        }

        /*
         * Un fornitore reale che non restituisce nulla e quasi sempre una
         * chiave sbagliata o un limite superato: il provider registra il
         * dettaglio nei log e va avanti, ma senza questo avviso il comando
         * stamperebbe "aggiornato" e chi lo lancia penserebbe che funzioni.
         */
        if ($provider->name() !== 'mock' && $upcoming === 0 && $results === 0 && $errors === []) {
            // Il fornitore sa perche non ha dato niente: chiederglielo evita
            // di rispondere "non ha funzionato" a chi vuole sapere cosa
            // sistemare. Se non lo sa, resta la traccia nei log.
            $errors[] = $provider->lastError()
                ?? 'il fornitore non ha restituito alcuna partita (il dettaglio e in storage/logs)';
        }

        return new FootballSyncReport($provider->name(), $upcoming, $results, $errors);
    }

    /** Normalizza il dato del fornitore e lo scrive a database. */
    private function store(FootballMatchData $match, string $providerName): void
    {
        $teamName = $this->config->string('services.football.team_name', 'Fiorentina');
        $isHome = $match->isHomeFor($teamName);

        $this->repository->upsert([
            'provider' => $providerName,
            'external_id' => $match->externalId,
            'competition' => $match->competition,
            'competition_code' => $match->competitionCode,
            'round_label' => $match->roundLabel,
            'season' => $match->season,
            'home_team' => $match->homeTeam,
            'away_team' => $match->awayTeam,
            'home_team_logo' => $match->homeTeamLogo,
            'away_team_logo' => $match->awayTeamLogo,
            'is_home' => $isHome ? 1 : 0,
            'opponent' => $match->opponentOf($teamName),
            'venue' => $match->venue,
            'kickoff_at' => $match->kickoffAt->format('Y-m-d H:i:s'),
            'kickoff_time_confirmed' => $match->timeConfirmed ? 1 : 0,
            'status' => $match->status,
            'home_score' => $match->homeScore,
            'away_score' => $match->awayScore,
        ]);
    }

    /** Nomi ammessi per il fornitore. */
    private const FORNITORI = ['mock', 'football-data', 'apifootball'];

    /**
     * Ripulisce il valore letto dal .env.
     *
     * Spazi, maiuscole, virgolette e punteggiatura finale sono gli inciampi
     * tipici di un valore copiato da un messaggio o da una guida: non c'e
     * ragione di trattarli come errori quando l'intenzione e evidente.
     */
    private static function normalizzaNome(string $valore): string
    {
        $superflui = " " . chr(9) . chr(10) . chr(13) . chr(0) . chr(11) . ".,;:" . chr(39) . chr(34);

        return strtolower(trim($valore, $superflui));
    }

    /** Costruisce il fornitore indicato dalla configurazione. */
    public function provider(): FootballApiInterface
    {
        $teamName = $this->config->string('services.football.team_name', 'Fiorentina');
        $season = $this->config->int('services.football.season', (int) date('Y'));
        $apiKey = $this->config->string('services.football.api_key');

        $configured = self::normalizzaNome($this->config->string('services.football.provider', 'mock'));

        /*
         * Un nome sconosciuto ricadeva in silenzio sul fornitore fittizio, e il
         * sito continuava a mostrare partite inventate come se nulla fosse.
         * E successo davvero, per un punto di troppo copiato insieme al nome:
         * "football-data." invece di "football-data". Ora si sente.
         */
        if (! in_array($configured, self::FORNITORI, true)) {
            $this->logger->error('Fornitore calcio sconosciuto: uso quello dimostrativo.', [
                'configurato' => $this->config->string('services.football.provider'),
                'ammessi' => self::FORNITORI,
            ]);
            $configured = 'mock';
        }

        // Senza chiave non ha senso tentare il fornitore reale: ricadiamo su
        // quello fittizio, così il sito resta popolato e navigabile.
        if ($configured !== 'mock' && trim($apiKey) === '') {
            $this->logger->info('Chiave API calcio assente: uso il fornitore fittizio.');
            $configured = 'mock';
        }

        return match ($configured) {
            'football-data' => new FootballDataProvider(
                http: $this->http,
                logger: $this->logger,
                apiKey: $apiKey,
                teamId: $this->config->int('services.football.team_id'),
                teamName: $teamName,
            ),
            'apifootball' => new ApiFootballProvider(
                http: $this->http,
                logger: $this->logger,
                apiKey: $apiKey,
                baseUrl: $this->config->string('services.football.base_url'),
                teamId: $this->config->int('services.football.team_id'),
                season: $season,
                teamName: $teamName,
            ),
            default => new MockFootballProvider($teamName, $season),
        };
    }

    public function providerName(): string
    {
        return $this->provider()->name();
    }

    public function lastSyncedAt(): ?string
    {
        return $this->repository->lastSyncedAt();
    }

    /** Indica se i dati sono più vecchi della soglia configurata. */
    public function isStale(): bool
    {
        $last = $this->lastSyncedAt();

        if ($last === null) {
            return true;
        }

        $ttlMinutes = $this->config->int('services.football.cache_ttl_minutes', 360);

        return (time() - strtotime($last)) > $ttlMinutes * 60;
    }
}
