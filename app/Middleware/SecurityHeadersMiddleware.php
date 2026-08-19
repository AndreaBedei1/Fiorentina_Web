<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use Closure;

/**
 * Intestazioni di sicurezza applicate a ogni risposta.
 *
 * Sono impostate qui, e non solo nel file .htaccess, perche restino valide
 * anche se il sito venisse spostato su un server configurato diversamente.
 *
 * La Content-Security-Policy e volutamente stretta ma non tanto da rompere le
 * integrazioni: nessuna risorsa esterna e ammessa, perche il progetto non ne
 * carica nessuna (font di sistema, nessun CDN, miniature social archiviate in
 * locale). L'unica concessione riguarda gli stili inline, necessari per le
 * immagini che dichiarano il proprio rapporto d'aspetto.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->header('X-Frame-Options', 'SAMEORIGIN');
        $response->header(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
        );

        if ($this->config->bool('security.csp.enabled', true)) {
            $header = $this->config->bool('security.csp.report_only')
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            $response->header($header, $this->policy($request));
        }

        if ($this->config->bool('app.force_https') && $request->isSecure()) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Le pagine dell'area riservata non devono restare nella cache del
        // browser: il tasto "indietro" dopo il logout mostrerebbe dati privati.
        if (str_starts_with($request->path(), '/admin')) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->header('Pragma', 'no-cache');
        }

        return $response;
    }

    private function policy(Request $request): string
    {
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            // Gli stili inline servono agli attributi style delle immagini
            // responsive; gli script inline non sono ammessi.
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self'",
            "connect-src 'self'",
            "media-src 'self'",
            "manifest-src 'self'",
        ];

        // In sviluppo il server Vite serve moduli e websocket da un'altra porta.
        if ($this->config->string('app.env') === 'local') {
            $directives[2] = "object-src 'none'";
            $directives[7] = "style-src 'self' 'unsafe-inline' http://127.0.0.1:5173 http://localhost:5173";
            $directives[8] = "script-src 'self' 'unsafe-inline' http://127.0.0.1:5173 http://localhost:5173";
            $directives[9] = "connect-src 'self' ws://127.0.0.1:5173 ws://localhost:5173 http://127.0.0.1:5173";
        }

        return implode('; ', $directives);
    }
}
