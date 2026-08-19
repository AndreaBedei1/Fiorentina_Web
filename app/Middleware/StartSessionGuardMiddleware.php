<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;
use Closure;

/**
 * Garantisce che la sessione sia avviata prima di qualsiasi altro middleware.
 *
 * CSRF, messaggi flash e autenticazione dipendono tutti dalla sessione: farla
 * partire qui evita che un ordine diverso di registrazione dei middleware
 * produca errori difficili da diagnosticare.
 */
final class StartSessionGuardMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Session $session)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->session->isStarted()) {
            $this->session->start();
        }

        return $next($request);
    }
}
