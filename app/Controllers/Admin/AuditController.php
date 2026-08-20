<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\UrlGenerator;
use App\Core\Session\Session;
use App\Core\View\ViewRenderer;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;

/**
 * Registro delle attività. Riservato ai super amministratori.
 *
 * E in sola lettura, di proposito: un registro modificabile da chi vi compare
 * non e un registro.
 */
final class AuditController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly AuditLogRepository $logs,
        private readonly UserRepository $users,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $this->requireSuperAdmin();

        $action = $request->string('azione');
        $userId = $request->nullableInt('utente');

        return $this->render('admin/audit.twig', [
            'seo' => $this->seo('Registro attivita')->withNoindex(),
            'paginator' => $this->logs->paginate(
                page: $this->page($request),
                perPage: 50,
                action: $action !== '' ? $action : null,
                userId: $userId,
                basePath: $this->url->route('admin.audit.index'),
            ),
            'actions' => $this->logs->distinctActions(),
            'users' => $this->users->all(includeDeleted: true),
            'activeAction' => $action,
            'activeUser' => $userId,
        ]);
    }
}
