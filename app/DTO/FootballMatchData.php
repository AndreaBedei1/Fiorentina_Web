<?php

declare(strict_types=1);

namespace App\DTO;

use DateTimeImmutable;

/**
 * Partita così come arriva da un provider, prima di essere normalizzata e
 * scritta a database. Confine fra il mondo esterno e il dominio applicativo:
 * ogni provider produce questo tipo, e il resto del codice non sa quale API
 * sia stata usata.
 */
final readonly class FootballMatchData
{
    public function __construct(
        public string $externalId,
        public string $competition,
        public string $homeTeam,
        public string $awayTeam,
        public DateTimeImmutable $kickoffAt,
        public string $status = 'scheduled',
        public ?string $competitionCode = null,
        public ?string $roundLabel = null,
        public ?int $season = null,
        public ?string $homeTeamLogo = null,
        public ?string $awayTeamLogo = null,
        public ?string $venue = null,
        public ?int $homeScore = null,
        public ?int $awayScore = null,
    ) {
    }

    public function isHomeFor(string $teamName): bool
    {
        return $this->normalize($this->homeTeam) === $this->normalize($teamName);
    }

    public function opponentOf(string $teamName): string
    {
        return $this->isHomeFor($teamName) ? $this->awayTeam : $this->homeTeam;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
