<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Genera i token monouso (invito amministratore, reset password).
 *
 * Sul database salviamo solo l'hash SHA-256: un dump del DB non permette di
 * usare gli inviti pendenti. SHA-256 e adeguato perche il token ha gia 256 bit
 * di entropia, quindi non e attaccabile per forza bruta come una password.
 */
final class TokenGenerator
{
    /** Token in chiaro (va nella mail) + hash da salvare a DB. */
    public function generate(): TokenPair
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return new TokenPair($plain, self::hash($plain));
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /** Confronto a tempo costante fra hash. */
    public static function matches(string $plainToken, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($plainToken));
    }
}
