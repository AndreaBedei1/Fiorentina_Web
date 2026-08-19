<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\ViewRenderer;
use Closure;

/**
 * Modalita manutenzione del sito pubblico.
 *
 * L'area riservata resta sempre raggiungibile: e proprio durante la
 * manutenzione che gli amministratori hanno bisogno di entrare per sistemare
 * contenuti o verificare il risultato.
 */
final class MaintenanceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ViewRenderer $view,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->config->bool('app.maintenance')) {
            return $next($request);
        }

        $path = $request->path();

        if (str_starts_with($path, '/admin') || str_starts_with($path, '/assets') || str_starts_with($path, '/uploads')) {
            return $next($request);
        }

        return $this->view
            ->response('errors/503.twig', ['status' => 503], 503)
            // Retry-After dice ai motori di ricerca che si tratta di una
            // condizione temporanea: senza, rischiano di deindicizzare le pagine.
            ->header('Retry-After', '3600');
    }
}
