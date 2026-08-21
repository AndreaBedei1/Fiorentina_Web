<?php

declare(strict_types=1);

namespace App\Services\Football;

/** Riepilogo di una sincronizzazione del calendario partite. */
final readonly class FootballSyncReport
{
    /** @param list<string> $errors */
    public function __construct(
        public string $provider,
        public int $upcomingCount,
        public int $resultsCount,
        public array $errors = [],
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** La prima ragione del fallimento, quella da mostrare a chi guarda. */
    public function reason(): ?string
    {
        return $this->errors[0] ?? null;
    }

    public function summary(): string
    {
        return sprintf(
            'Fornitore "%s": %d partite in programma, %d risultati aggiornati%s.',
            $this->provider,
            $this->upcomingCount,
            $this->resultsCount,
            $this->hasErrors() ? sprintf(', %d con avvisi', count($this->errors)) : '',
        );
    }
}
