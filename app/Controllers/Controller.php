<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Http\JsonResponse;
use App\Core\Http\RedirectResponse;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\UrlGenerator;
use App\Core\Session\Session;
use App\Core\View\ViewRenderer;
use App\Exceptions\HttpException;
use App\Models\User;
use App\Services\AuthService;
use App\Services\SeoMeta;

/**
 * Base comune a tutti i controller.
 *
 * Fornisce solo scorciatoie di infrastruttura (rendering, redirect, messaggi,
 * permessi). La logica di dominio resta nei service: un controller che cresce
 * oltre la lettura della richiesta e la scelta della risposta e un controller
 * che sta facendo il lavoro di qualcun altro.
 */
abstract class Controller
{
    public function __construct(
        protected readonly ViewRenderer $view,
        protected readonly Session $session,
        protected readonly UrlGenerator $url,
        protected readonly AuthService $auth,
        protected readonly Config $config,
    ) {
    }

    // -----------------------------------------------------------------------
    //  Risposte
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return $this->view->response($template, $data, $status);
    }

    protected function redirect(string $path): RedirectResponse
    {
        return RedirectResponse::to($path);
    }

    /** @param array<string, string|int> $parameters */
    protected function redirectToRoute(string $name, array $parameters = []): RedirectResponse
    {
        return RedirectResponse::to($this->url->route($name, $parameters));
    }

    protected function back(Request $request, string $fallback = '/'): RedirectResponse
    {
        return RedirectResponse::back($request, $fallback);
    }

    protected function json(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }

    // -----------------------------------------------------------------------
    //  Messaggi all'utente
    // -----------------------------------------------------------------------

    protected function success(string $message): void
    {
        $this->session->flash('success', $message);
    }

    protected function error(string $message): void
    {
        $this->session->flash('error', $message);
    }

    protected function warning(string $message): void
    {
        $this->session->flash('warning', $message);
    }

    protected function info(string $message): void
    {
        $this->session->flash('info', $message);
    }

    /**
     * Ripropone il form con i dati inseriti e gli errori di validazione.
     *
     * @param array<string, mixed>        $input
     * @param array<string, list<string>> $errors
     */
    protected function backWithErrors(Request $request, array $input, array $errors, string $fallback = '/'): RedirectResponse
    {
        $this->session->flashInput($input);
        $this->session->flashErrors($errors);
        $this->error('Controlla i campi segnalati e riprova.');

        return $this->back($request, $fallback);
    }

    // -----------------------------------------------------------------------
    //  Utente e permessi
    // -----------------------------------------------------------------------

    /** Utente autenticato. Da usare solo dietro AdminAuthMiddleware. */
    protected function currentUser(): User
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        return $user;
    }

    /** Interrompe con 403 se manca il permesso richiesto. */
    protected function authorize(string $permission): void
    {
        if (! $this->auth->can($permission)) {
            throw HttpException::forbidden(
                'Non hai i permessi necessari per questa operazione.'
            );
        }
    }

    protected function requireSuperAdmin(): User
    {
        $user = $this->currentUser();

        if (! $user->isSuperAdmin()) {
            throw HttpException::forbidden('Questa operazione e riservata ai super amministratori.');
        }

        return $user;
    }

    // -----------------------------------------------------------------------
    //  Utilita
    // -----------------------------------------------------------------------

    protected function seo(string $title, string $description = ''): SeoMeta
    {
        return SeoMeta::make($title, $description);
    }

    /** Numero di pagina richiesto, sempre positivo. */
    protected function page(Request $request): int
    {
        return max(1, $request->int('pagina', 1));
    }

    protected function notFound(string $message = 'La pagina che cerchi non esiste o e stata spostata.'): never
    {
        throw HttpException::notFound($message);
    }

    /**
     * Destinazione dopo il login, se ne era stata memorizzata una.
     * Il valore viene comunque normalizzato: non deve poter diventare un
     * redirect verso un sito esterno.
     */
    protected function intendedUrl(string $default = '/admin'): string
    {
        $intended = $this->session->get('admin_intended_url');
        $this->session->forget('admin_intended_url');

        if (! is_string($intended) || $intended === '') {
            return $default;
        }

        $safe = RedirectResponse::sanitizeInternalPath($intended);

        return str_starts_with($safe, '/admin') ? $safe : $default;
    }
}
