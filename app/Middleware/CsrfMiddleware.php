<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Csrf;
use App\Exceptions\HttpException;
use Closure;

/**
 * Verifica del token CSRF su tutte le richieste che modificano dati.
 *
 * Applicato globalmente e non rotta per rotta: e l'unico modo per essere certi
 * che nessun form aggiunto in futuro resti scoperto per dimenticanza.
 *
 * Le richieste di sola lettura (GET, HEAD, OPTIONS) non vengono controllate,
 * perché per definizione non devono cambiare nulla.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->realMethod(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $token = $request->post(Csrf::FIELD);
        $token = is_string($token) ? $token : null;

        // Le chiamate JavaScript inviano il token nell'intestazione.
        $token ??= $request->header(Csrf::HEADER);

        if (! $this->csrf->verify($token)) {
            throw HttpException::pageExpired(
                'La sessione è scaduta oppure la pagina è rimasta aperta troppo a lungo. Ricarica e riprova.'
            );
        }

        return $next($request);
    }
}
