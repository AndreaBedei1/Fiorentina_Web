<?php

declare(strict_types=1);

namespace App\Services\Football;

use App\DTO\FootballMatchData;
use DateTimeImmutable;

/**
 * Fornitore fittizio con dati verosimili.
 *
 * Grazie a questo il sito e completamente navigabile senza alcuna chiave API:
 * si può valutare la grafica del calendario e della homepage prima ancora di
 * scegliere un fornitore a pagamento. E anche il fornitore usato dai test, che
 * non devono dipendere dalla rete.
 */
final class MockFootballProvider implements FootballApiInterface
{
    /** Avversari reali della Serie A: rendono la demo credibile. */
    private const OPPONENTS = [
        ['Bologna', 'Stadio Renato Dall Ara'],
        ['Juventus', 'Allianz Stadium'],
        ['Inter', 'Stadio Giuseppe Meazza'],
        ['Napoli', 'Stadio Diego Armando Maradona'],
        ['Milan', 'Stadio Giuseppe Meazza'],
        ['Lazio', 'Stadio Olimpico'],
        ['Roma', 'Stadio Olimpico'],
        ['Torino', 'Stadio Olimpico Grande Torino'],
        ['Atalanta', 'Gewiss Stadium'],
        ['Empoli', 'Stadio Carlo Castellani'],
    ];

    private const HOME_VENUE = 'Stadio Artemio Franchi';

    public function __construct(
        private readonly string $teamName = 'Fiorentina',
        private readonly int $season = 2026,
    ) {
    }

    /**
     * Fasce orarie del campionato: scostamento dalla domenica, ora, minuti.
     * Sabato = -1, domenica = 0, lunedi = +1.
     *
     * @var list<array{0: int, 1: int, 2: int}>
     */
    private const SLOTS = [
        [0, 20, 45],   // domenica sera
        [-1, 18, 0],   // sabato pomeriggio
        [0, 15, 0],    // domenica pomeriggio
        [1, 20, 45],   // lunedi sera (posticipo)
        [0, 12, 30],   // domenica, lunch match
        [-1, 20, 45],  // sabato sera
    ];

    public function name(): string
    {
        return 'mock';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** I dati sono inventati sul posto: non c'e niente che possa fallire. */
    public function lastError(): ?string
    {
        return null;
    }

    /** @return list<FootballMatchData> */
    public function fetchUpcomingMatches(int $limit = 10): array
    {
        $matches = [];
        $base = (new DateTimeImmutable('next sunday'))->setTime(15, 0);

        for ($i = 0; $i < min($limit, count(self::OPPONENTS)); $i++) {
            [$opponent, $awayVenue] = self::OPPONENTS[$i];
            $isHome = $i % 2 === 0;

            // Giorno e orario ruotano fra le fasce reali del campionato. Con
            // tutte le partite di domenica alle 15 i dati dimostrativi
            // sembravano plausibili solo a chi il calcio non lo segue, e
            // nessuno si accorgeva che erano inventati.
            [$giorni, $ora, $minuti] = self::SLOTS[$i % count(self::SLOTS)];

            $kickoff = $base->modify(sprintf('+%d weeks', $i))
                ->modify(sprintf('+%d days', $giorni))
                ->setTime($ora, $minuti);

            $matches[] = new FootballMatchData(
                externalId: sprintf('mock-up-%d-%s', $this->season, strtolower(str_replace(' ', '-', $opponent))),
                competition: $i % 5 === 4 ? 'Coppa Italia' : 'Serie A',
                homeTeam: $isHome ? $this->teamName : $opponent,
                awayTeam: $isHome ? $opponent : $this->teamName,
                kickoffAt: $kickoff,
                status: 'scheduled',
                competitionCode: $i % 5 === 4 ? 'CI' : 'SA',
                roundLabel: $i % 5 === 4 ? 'Ottavi di finale' : sprintf('Giornata %d', $i + 12),
                season: $this->season,
                venue: $isHome ? self::HOME_VENUE : $awayVenue,
            );
        }

        return $matches;
    }

    /** @return list<FootballMatchData> */
    public function fetchRecentResults(int $limit = 10): array
    {
        $matches = [];
        $base = (new DateTimeImmutable('last sunday'))->setTime(15, 0);

        // Punteggi fissi e non casuali: un risultato che cambia a ogni
        // esecuzione renderebbe i test instabili e la demo poco credibile.
        $scores = [[2, 1], [0, 0], [1, 3], [2, 2], [3, 0], [1, 0], [0, 2], [2, 0], [1, 1], [4, 1]];

        for ($i = 0; $i < min($limit, count(self::OPPONENTS)); $i++) {
            [$opponent, $awayVenue] = self::OPPONENTS[($i + 3) % count(self::OPPONENTS)];
            $isHome = $i % 2 === 1;
            $kickoff = $base->modify(sprintf('-%d weeks', $i));
            [$homeScore, $awayScore] = $scores[$i % count($scores)];

            $matches[] = new FootballMatchData(
                externalId: sprintf('mock-res-%d-%d-%s', $this->season, $i, strtolower(str_replace(' ', '-', $opponent))),
                competition: 'Serie A',
                homeTeam: $isHome ? $this->teamName : $opponent,
                awayTeam: $isHome ? $opponent : $this->teamName,
                kickoffAt: $kickoff,
                status: 'finished',
                competitionCode: 'SA',
                roundLabel: sprintf('Giornata %d', 11 - $i),
                season: $this->season,
                venue: $isHome ? self::HOME_VENUE : $awayVenue,
                homeScore: $homeScore,
                awayScore: $awayScore,
            );
        }

        return $matches;
    }
}
