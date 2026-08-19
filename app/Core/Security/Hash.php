<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Hashing delle password.
 *
 * Argon2id quando il runtime lo espone (PHP compilato con libargon2), bcrypt
 * altrimenti: Aruba non garantisce Argon2 su tutti i piani, quindi il fallback
 * non e teorico. `needsRehash()` consente la migrazione trasparente al login.
 */
final class Hash
{
    /** @var array<string, int|string> */
    private array $argonOptions;

    public function __construct(
        private readonly int $bcryptCost = 12,
        int $argonMemoryCost = 65536,
        int $argonTimeCost = 4,
        int $argonThreads = 2,
    ) {
        $this->argonOptions = [
            'memory_cost' => $argonMemoryCost,
            'time_cost' => $argonTimeCost,
            'threads' => $argonThreads,
        ];
    }

    public function algorithm(): string
    {
        return $this->supportsArgon2id() ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public function algorithmName(): string
    {
        return $this->supportsArgon2id() ? 'argon2id' : 'bcrypt';
    }

    public function make(string $password): string
    {
        $hash = $this->supportsArgon2id()
            ? password_hash($password, PASSWORD_ARGON2ID, $this->argonOptions)
            : password_hash($password, PASSWORD_BCRYPT, ['cost' => $this->bcryptCost]);

        if (! is_string($hash) || $hash === '') {
            throw new \RuntimeException('Generazione dell\'hash della password non riuscita.');
        }

        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        if ($hash === '') {
            // Nessun hash memorizzato: eseguiamo comunque un confronto fittizio per
            // non rendere distinguibile il caso "utente inesistente" dal timing.
            password_verify($password, '$2y$12$usesomesillystringfoeuFOaWzcwSXNGDlxHzApuBXOWnJFqfHy');

            return false;
        }

        return password_verify($password, $hash);
    }

    /** Indica se l'hash va rigenerato con i parametri correnti (upgrade al login). */
    public function needsRehash(string $hash): bool
    {
        return $this->supportsArgon2id()
            ? password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->argonOptions)
            : password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $this->bcryptCost]);
    }

    public function supportsArgon2id(): bool
    {
        return defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true);
    }
}
