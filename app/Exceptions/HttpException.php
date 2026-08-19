<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Errore che si traduce direttamente in uno status HTTP.
 *
 * Lanciarla dai controller consente di gestire 403/404/419 in un unico punto
 * (ExceptionHandler) senza duplicare la logica di rendering delle pagine errore.
 */
class HttpException extends \RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public static function notFound(string $message = 'Pagina non trovata.'): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = 'Non hai i permessi necessari per questa operazione.'): self
    {
        return new self(403, $message);
    }

    public static function unauthorized(string $message = 'Autenticazione richiesta.'): self
    {
        return new self(401, $message);
    }

    public static function badRequest(string $message = 'Richiesta non valida.'): self
    {
        return new self(400, $message);
    }

    /** 419: sessione scaduta / token CSRF non valido. */
    public static function pageExpired(string $message = 'Sessione scaduta. Ricarica la pagina e riprova.'): self
    {
        return new self(419, $message);
    }

    public static function tooManyRequests(string $message = 'Troppi tentativi. Riprova piu tardi.', int $retryAfter = 60): self
    {
        return new self(429, $message, ['Retry-After' => (string) $retryAfter]);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(405, 'Metodo HTTP non consentito su questo indirizzo.', [
            'Allow' => implode(', ', $allowed),
        ]);
    }

    public static function serverError(string $message = 'Errore interno del server.', ?\Throwable $previous = null): self
    {
        return new self(500, $message, [], $previous);
    }
}
