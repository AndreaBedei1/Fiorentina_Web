<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Session\Session;

/**
 * Token CSRF sincronizzato con la sessione.
 *
 * Un solo token per sessione (non uno per form): e sufficiente contro il CSRF e
 * non rompe la navigazione con piu schede aperte, che invece e un problema
 * concreto per gli amministratori che lavorano su piu contenuti insieme.
 * Il token viene rigenerato a ogni cambio di privilegio.
 */
final class Csrf
{
    public const FIELD = '_token';
    public const HEADER = 'X-CSRF-Token';

    private const SESSION_KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (! is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->put(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->put(self::SESSION_KEY, $token);

        return $token;
    }

    /** Confronto a tempo costante: un `===` qui esporrebbe un timing oracle. */
    public function verify(?string $candidate): bool
    {
        if ($candidate === null || $candidate === '') {
            return false;
        }

        $expected = $this->session->get(self::SESSION_KEY);

        if (! is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $candidate);
    }

    /** Campo hidden pronto da inserire nei form. */
    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars($this->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }
}
