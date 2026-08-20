<?php

declare(strict_types=1);

namespace App\Services\Football;

use App\Core\Support\HttpClient;
use App\DTO\FootballMatchData;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Fornitore basato su API-Football (api-sports.io), versione 3.
 *
 * Implementazione completa ma non attivabile finché non viene inserita una
 * chiave in `FOOTBALL_API_KEY`: la scelta del fornitore a pagamento resta
 * aperta, e finché non e presa il sito usa il fornitore fittizio.
 *
 * Per adottare un servizio diverso basta scrivere un'altra classe che
 * implementi FootballApiInterface e registrarla nella factory: nessun'altra
 * parte del progetto ne risente.
 */
final class ApiFootballProvider implements FootballApiInterface
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $teamId,
        private readonly int $season,
        private readonly string $teamName = 'Fiorentina',
    ) {
    }

    public function name(): string
    {
        return 'apifootball';
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '' && $this->teamId > 0;
    }

    /** @return list<FootballMatchData> */
    public function fetchUpcomingMatches(int $limit = 10): array
    {
        return $this->fetchFixtures(['next' => (string) max(1, min(50, $limit))]);
    }

    /** @return list<FootballMatchData> */
    public function fetchRecentResults(int $limit = 10): array
    {
        return $this->fetchFixtures(['last' => (string) max(1, min(50, $limit))]);
    }

    /**
     * @param array<string, string> $extraQuery
     * @return list<FootballMatchData>
     */
    private function fetchFixtures(array $extraQuery): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $query = array_merge([
            'team' => (string) $this->teamId,
            'season' => (string) $this->season,
            'timezone' => 'Europe/Rome',
        ], $extraQuery);

        $url = rtrim($this->baseUrl, '/') . '/fixtures?' . http_build_query($query);

        $response = $this->http->getJson($url, [
            'x-apisports-key: ' . $this->apiKey,
            'Accept: application/json',
        ]);

        if ($response === null) {
            $this->logger->warning('API calcio: nessuna risposta utilizzabile.', ['url' => $url]);

            return [];
        }

        // Il provider risponde 200 anche in caso di errore applicativo,
        // segnalandolo nella chiave "errors": va controllata esplicitamente.
        if (! empty($response['errors'])) {
            $this->logger->error('API calcio: risposta con errori.', ['errors' => $response['errors']]);

            return [];
        }

        $matches = [];

        foreach ($response['response'] ?? [] as $fixture) {
            $parsed = $this->parseFixture($fixture);

            if ($parsed !== null) {
                $matches[] = $parsed;
            }
        }

        return $matches;
    }

    /** @param array<string, mixed> $fixture */
    private function parseFixture(array $fixture): ?FootballMatchData
    {
        $info = $fixture['fixture'] ?? null;
        $teams = $fixture['teams'] ?? null;
        $league = $fixture['league'] ?? null;

        if (! is_array($info) || ! is_array($teams) || ! is_array($league)) {
            return null;
        }

        try {
            $kickoff = new DateTimeImmutable((string) ($info['date'] ?? 'now'));
        } catch (\Exception) {
            return null;
        }

        $goals = $fixture['goals'] ?? [];

        return new FootballMatchData(
            externalId: (string) ($info['id'] ?? ''),
            competition: (string) ($league['name'] ?? 'Competizione'),
            homeTeam: (string) ($teams['home']['name'] ?? ''),
            awayTeam: (string) ($teams['away']['name'] ?? ''),
            kickoffAt: $kickoff,
            status: $this->mapStatus((string) ($info['status']['short'] ?? 'NS')),
            competitionCode: isset($league['id']) ? (string) $league['id'] : null,
            roundLabel: isset($league['round']) ? (string) $league['round'] : null,
            season: isset($league['season']) ? (int) $league['season'] : null,
            homeTeamLogo: isset($teams['home']['logo']) ? (string) $teams['home']['logo'] : null,
            awayTeamLogo: isset($teams['away']['logo']) ? (string) $teams['away']['logo'] : null,
            venue: isset($info['venue']['name']) ? (string) $info['venue']['name'] : null,
            homeScore: isset($goals['home']) && $goals['home'] !== null ? (int) $goals['home'] : null,
            awayScore: isset($goals['away']) && $goals['away'] !== null ? (int) $goals['away'] : null,
        );
    }

    /** Traduce i codici di stato del fornitore nei quattro stati che usiamo. */
    private function mapStatus(string $short): string
    {
        return match ($short) {
            '1H', '2H', 'HT', 'ET', 'BT', 'P', 'LIVE', 'INT' => 'live',
            'FT', 'AET', 'PEN' => 'finished',
            'PST' => 'postponed',
            'CANC', 'ABD', 'AWD', 'WO' => 'cancelled',
            default => 'scheduled',
        };
    }
}
