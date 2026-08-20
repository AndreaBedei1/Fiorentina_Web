<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CastsRowValues;
use DateTimeImmutable;

/**
 * Partita della Fiorentina, copiata in locale dal provider esterno.
 *
 * Il sito legge esclusivamente questa tabella: nessuna pagina interroga l'API
 * in tempo reale. E cio che rende la homepage veloce è indipendente dallo stato
 * del servizio esterno.
 */
final class FootballMatch
{
    use CastsRowValues;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_LIVE = 'live';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_POSTPONED = 'postponed';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        public readonly int $id,
        public readonly string $provider,
        public readonly string $externalId,
        public readonly string $competition,
        public readonly ?string $competitionCode,
        public readonly ?string $roundLabel,
        public readonly ?int $season,
        public readonly string $homeTeam,
        public readonly string $awayTeam,
        public readonly ?string $homeTeamLogo,
        public readonly ?string $awayTeamLogo,
        public readonly bool $isHome,
        public readonly string $opponent,
        public readonly ?string $venue,
        public readonly DateTimeImmutable $kickoffAt,
        public readonly string $status,
        public readonly ?int $homeScore,
        public readonly ?int $awayScore,
        public readonly bool $isManual,
        public readonly ?DateTimeImmutable $syncedAt,
        /**
         * Falso quando la lega ha fissato la data ma non ancora l'ora. In quel
         * caso l'orario memorizzato e un segnaposto e non va mostrato.
         */
        public readonly bool $kickoffTimeConfirmed = true,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::castInt($row, 'id'),
            provider: self::castString($row, 'provider', 'mock'),
            externalId: self::castString($row, 'external_id'),
            competition: self::castString($row, 'competition'),
            competitionCode: self::castNullableString($row, 'competition_code'),
            roundLabel: self::castNullableString($row, 'round_label'),
            season: self::castNullableInt($row, 'season'),
            homeTeam: self::castString($row, 'home_team'),
            awayTeam: self::castString($row, 'away_team'),
            homeTeamLogo: self::castNullableString($row, 'home_team_logo'),
            awayTeamLogo: self::castNullableString($row, 'away_team_logo'),
            isHome: self::castBool($row, 'is_home', true),
            opponent: self::castString($row, 'opponent'),
            venue: self::castNullableString($row, 'venue'),
            kickoffAt: self::castDate($row, 'kickoff_at') ?? new DateTimeImmutable(),
            status: self::castString($row, 'status', self::STATUS_SCHEDULED),
            homeScore: self::castNullableInt($row, 'home_score'),
            awayScore: self::castNullableInt($row, 'away_score'),
            isManual: self::castBool($row, 'is_manual'),
            syncedAt: self::castDate($row, 'synced_at'),
            kickoffTimeConfirmed: self::castBool($row, 'kickoff_time_confirmed', true),
        );
    }

    /** "Fiorentina - Bologna" */
    public function title(): string
    {
        return $this->homeTeam . ' - ' . $this->awayTeam;
    }

    public function venueLabel(): string
    {
        return $this->isHome ? 'In casa' : 'In trasferta';
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    public function isUpcoming(): bool
    {
        return in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_LIVE], true)
            && $this->kickoffAt >= new DateTimeImmutable('-3 hours');
    }

    public function hasScore(): bool
    {
        return $this->homeScore !== null && $this->awayScore !== null;
    }

    public function scoreLabel(): string
    {
        return $this->hasScore() ? $this->homeScore . ' - ' . $this->awayScore : '';
    }

    /** Esito dal punto di vista della Fiorentina: W, D, L oppure null. */
    public function result(): ?string
    {
        if (! $this->hasScore() || ! $this->isFinished()) {
            return null;
        }

        $own = $this->isHome ? $this->homeScore : $this->awayScore;
        $other = $this->isHome ? $this->awayScore : $this->homeScore;

        return match (true) {
            $own > $other => 'W',
            $own < $other => 'L',
            default => 'D',
        };
    }

    public function resultLabel(): string
    {
        return match ($this->result()) {
            'W' => 'Vittoria',
            'L' => 'Sconfitta',
            'D' => 'Pareggio',
            default => '',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_LIVE => 'In corso',
            self::STATUS_FINISHED => 'Terminata',
            self::STATUS_POSTPONED => 'Rinviata',
            self::STATUS_CANCELLED => 'Annullata',
            default => 'In programma',
        };
    }
}
