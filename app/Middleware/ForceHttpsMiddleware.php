<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Http\RedirectResponse;
use App\Core\Http\Request;
use App\Core\Http\Response;
use Closure;

/**
 * Redirect a HTTPS e HSTS in produzione.
 *
 * Su Aruba il certificato viene gestito dal pannello e il TLS termina prima di
 * PHP: il redirect a livello applicativo integra quello del file .htaccess,
 * così la protezione resta anche se la configurazione del server cambia.
 */
final class ForceHttpsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->config->bool('app.force_https') || $request->isSecure()) {
            return $next($request);
        }

        // Solo le richieste di lettura vengono redirette: reindirizzare una POST
        // ne perderebbe il corpo, e il browser la trasformerebbe in GET.
        if ($request->isGet()) {
            return RedirectResponse::away(
                'https://' . $request->host() . $request->fullPath(),
                301,
            );
        }

        return $next($request);
    }
}
