<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use Closure;

/**
 * Contratto dei middleware.
 *
 * Ogni middleware avvolge il resto della catena: può agire prima di passare la
 * richiesta a `$next`, dopo averne ricevuto la risposta, oppure interromperla
 * restituendo direttamente una Response.
 */
interface MiddlewareInterface
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response;
}
