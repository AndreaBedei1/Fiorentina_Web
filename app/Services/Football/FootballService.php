<?php

declare(strict_types=1);

namespace App\Services\Football;

use App\Core\Config;
use App\Core\Support\HttpClient;
use App\DTO\FootballMatchData;
use App\Models\FootballMatch;
use App\Repositories\FootballMatchRepository;
use App\Services\AuditLogger;
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
        private readonly AuditLogger $audit,
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

        $this->audit->logSystem(
            AuditLogger::SYNC_RUN,
            sprintf('Sincronizzazione calcio (%s): %d partite, %d risultati', $provider->name(), $upcoming, $results),
            ['provider' => $provider->name(), 'upcoming' => $upcoming, 'results' => $results, 'errors' => count($errors)],
        );

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
            'status' => $match->status,
            'home_score' => $match->homeScore,
            'away_score' => $match->awayScore,
        ]);
    }

    /** Costruisce il fornitore indicato dalla configurazione. */
    public function provider(): FootballApiInterface
    {
        $teamName = $this->config->string('services.football.team_name', 'Fiorentina');
        $season = $this->config->int('services.football.season', (int) date('Y'));
        $apiKey = $this->config->string('services.football.api_key');

        $configured = $this->config->string('services.football.provider', 'mock');

        // Senza chiave non ha senso tentare il fornitore reale: ricadiamo su
        // quello fittizio, cosi il sito resta popolato e navigabile.
        if ($configured !== 'mock' && trim($apiKey) === '') {
            $this->logger->info('Chiave API calcio assente: uso il fornitore fittizio.');
            $configured = 'mock';
        }

        return match ($configured) {
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

    /** Indica se i dati sono piu vecchi della soglia configurata. */
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
