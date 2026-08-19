<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuthTokenRepository;

/**
 * Limitatore di frequenza basato su database.
 *
 * Su hosting condiviso non possiamo contare su Redis o su un APCu persistente
 * fra processi: una tabella con chiave unica e una finestra temporale e la
 * soluzione che funziona davvero su Aruba Easy.
 */
final class RateLimiter
{
    public function __construct(private readonly AuthTokenRepository $repository)
    {
    }

    /** Numero di tentativi gia registrati nella finestra corrente. */
    public function attempts(string $action, string $identifier): int
    {
        return $this->repository->findRateLimit($this->bucket($action, $identifier))['attempts'] ?? 0;
    }

    public function tooManyAttempts(string $action, string $identifier, int $maxAttempts): bool
    {
        return $this->attempts($action, $identifier) >= $maxAttempts;
    }

    /** Registra un tentativo e restituisce il totale nella finestra. */
    public function hit(string $action, string $identifier, int $decayMinutes): int
    {
        return $this->repository->hitRateLimit($this->bucket($action, $identifier), $decayMinutes);
    }

    public function clear(string $action, string $identifier): void
    {
        $this->repository->clearRateLimit($this->bucket($action, $identifier));
    }

    /** Secondi mancanti alla riapertura, per il messaggio all'utente e per Retry-After. */
    public function secondsUntilReset(string $action, string $identifier): int
    {
        $entry = $this->repository->findRateLimit($this->bucket($action, $identifier));

        if ($entry === null) {
            return 0;
        }

        return max(0, strtotime($entry['expires_at']) - time());
    }

    public function remaining(string $action, string $identifier, int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->attempts($action, $identifier));
    }

    /**
     * La chiave e un hash: l'identificatore puo essere un indirizzo email o un
     * IP, e non c'e ragione di conservarli in chiaro in una tabella tecnica.
     */
    private function bucket(string $action, string $identifier): string
    {
        return $action . ':' . hash('sha256', mb_strtolower(trim($identifier)));
    }
}
