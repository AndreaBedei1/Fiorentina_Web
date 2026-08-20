<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Exceptions\HttpException;
use App\Services\AuthService;
use Closure;

/**
 * Riserva una rotta ai soli super amministratori.
 *
 * Copre gestione degli account, impostazioni e registro attività. E il secondo
 * livello di difesa: i controller ricontrollano comunque i permessi prima di
 * ogni operazione sensibile.
 */
final class RequireSuperAdminMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->auth->check()) {
            throw HttpException::unauthorized();
        }

        if (! $this->auth->isSuperAdmin()) {
            throw HttpException::forbidden(
                'Questa sezione e riservata ai super amministratori.'
            );
        }

        return $next($request);
    }
}
