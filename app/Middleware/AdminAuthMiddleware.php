<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\RedirectResponse;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Services\AuthService;
use Closure;

/**
 * Accesso consentito ai soli amministratori autenticati.
 *
 * Applicato a tutto il gruppo di rotte /admin salvo login, recupero password e
 * accettazione invito. Il controllo e sempre lato server: nascondere una voce
 * di menu non protegge nulla.
 */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Session $session,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->check()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return new \App\Core\Http\JsonResponse([
                'ok' => false,
                'message' => 'Sessione scaduta. Ricarica la pagina e accedi di nuovo.',
            ], 401);
        }

        // Memorizziamo la destinazione richiesta per tornarci dopo il login,
        // ma solo se e una GET: rimandare a una POST non avrebbe senso.
        if ($request->isGet()) {
            $this->session->put('admin_intended_url', $request->fullPath());
        }

        $this->session->flash('warning', 'Accedi per entrare nell area riservata.');

        return RedirectResponse::to('/admin/login');
    }
}
