<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * Esito di un tentativo di accesso.
 *
 * Il motivo del fallimento serve internamente (per l'audit log e per il
 * messaggio di attesa), ma cio che l'utente legge resta generico: solo il caso
 * "troppi tentativi" viene esplicitato, perche altrimenti sembrerebbe un guasto.
 */
final readonly class AuthResult
{
    public const REASON_INVALID = 'invalid_credentials';
    public const REASON_BLOCKED = 'blocked';
    public const REASON_INACTIVE = 'inactive';
    public const REASON_THROTTLED = 'throttled';

    private function __construct(
        public bool $successful,
        public ?User $user = null,
        public ?string $reason = null,
        public int $retryAfterSeconds = 0,
    ) {
    }

    public static function success(User $user): self
    {
        return new self(true, $user);
    }

    public static function invalidCredentials(): self
    {
        return new self(false, null, self::REASON_INVALID);
    }

    public static function blocked(): self
    {
        return new self(false, null, self::REASON_BLOCKED);
    }

    public static function inactive(): self
    {
        return new self(false, null, self::REASON_INACTIVE);
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(false, null, self::REASON_THROTTLED, $retryAfterSeconds);
    }

    public function failed(): bool
    {
        return ! $this->successful;
    }

    public function isThrottled(): bool
    {
        return $this->reason === self::REASON_THROTTLED;
    }

    /** Messaggio mostrabile all'utente. */
    public function message(): string
    {
        if ($this->successful) {
            return 'Accesso effettuato.';
        }

        if ($this->isThrottled()) {
            $minutes = max(1, (int) ceil($this->retryAfterSeconds / 60));

            return sprintf(
                'Troppi tentativi di accesso. Riprova fra %d %s.',
                $minutes,
                $minutes === 1 ? 'minuto' : 'minuti',
            );
        }

        // Volutamente identico per credenziali errate, account bloccato e
        // account non attivo: nessuna informazione utile a chi tenta a caso.
        return 'Email o password non corretti.';
    }
}
