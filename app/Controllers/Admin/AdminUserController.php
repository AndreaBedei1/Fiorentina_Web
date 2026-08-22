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
use App\Models\User;
use App\Repositories\AuthTokenRepository;
use App\Repositories\UserRepository;
use App\Services\AdminUserService;
use App\Services\AuthService;
use App\Services\PasswordResetService;
use App\Validation\PasswordPolicy;
use App\Validation\Validator;

/**
 * Gestione degli account amministratore. Riservata ai super amministratori.
 *
 * Le regole di salvaguardia (non si tocca l'ultimo super amministratore, non si
 * agisce su se stessi) vivono in AdminUserService: qui restano solo la lettura
 * della richiesta e la scelta della risposta.
 */
final class AdminUserController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly UserRepository $users,
        private readonly AuthTokenRepository $tokens,
        private readonly AdminUserService $adminUsers,
        private readonly PasswordResetService $passwordReset,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $this->requireSuperAdmin();

        return $this->render('admin/users/index.twig', [
            'seo' => $this->seo('Amministratori')->withNoindex(),
            'users' => $this->users->all(),
            'pendingInvites' => $this->tokens->pendingInvites(),
            'currentUserId' => $this->currentUser()->id,
        ]);
    }

    public function invite(Request $request): Response
    {
        $actor = $this->requireSuperAdmin();

        $validator = Validator::make($request->all())
            ->required('name', 'Il nome')->max('name', 120, 'Il nome')
            ->required('email', 'L indirizzo email')->email('email', 'L indirizzo email')
            ->in('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], 'Il ruolo');

        if ($validator->fails()) {
            return $this->backWithErrors($request, $request->all(), $validator->errors(), $this->url->route('admin.users.index'));
        }

        $data = $validator->validatedData();

        $result = $this->adminUsers->invite(
            (string) $data['name'],
            (string) $data['email'],
            (string) ($data['role'] ?? User::ROLE_ADMIN),
            $actor,
        );

        if ($result->failed()) {
            $this->error($result->message);

            return $this->redirectToRoute('admin.users.index');
        }

        $this->success($result->message);

        // Se la posta non e configurata mostriamo il link, così il super
        // amministratore può comunque consegnarlo a mano.
        if ($result->get('email_sent') === false) {
            $this->warning('Link di invito da consegnare manualmente: ' . (string) $result->get('link'));
        }

        return $this->redirectToRoute('admin.users.index');
    }

    public function resendInvite(Request $request): Response
    {
        $actor = $this->requireSuperAdmin();

        $result = $this->adminUsers->resendInvite($request->routeInt('id'), $actor);

        $result->successful ? $this->success($result->message) : $this->error($result->message);

        return $this->redirectToRoute('admin.users.index');
    }

    public function block(Request $request): Response
    {
        $actor = $this->requireSuperAdmin();

        $result = $this->adminUsers->block($request->routeInt('id'), $actor);

        $result->successful ? $this->success($result->message) : $this->error($result->message);

        return $this->redirectToRoute('admin.users.index');
    }

    public function unblock(Request $request): Response
    {
        $actor = $this->requireSuperAdmin();

        $result = $this->adminUsers->unblock($request->routeInt('id'), $actor);

        $result->successful ? $this->success($result->message) : $this->error($result->message);

        return $this->redirectToRoute('admin.users.index');
    }

    public function changeRole(Request $request): Response
    {
        $actor = $this->requireSuperAdmin();

        $result = $this->adminUsers->changeRole(
            $request->routeInt('id'),
            $request->string('role'),
            $actor,
        );

        $result->successful ? $this->success($result->message) : $this->error($result->message);

        return $this->redirectToRoute('admin.users.index');
    }

    public function destroy(Request $request): Response
    {
        $actor = $this->requireSuperAdmin();

        $result = $this->adminUsers->delete($request->routeInt('id'), $actor);

        $result->successful ? $this->success($result->message) : $this->error($result->message);

        return $this->redirectToRoute('admin.users.index');
    }

    // -----------------------------------------------------------------------
    //  Profilo personale (accessibile a ogni amministratore)
    // -----------------------------------------------------------------------

    public function profile(Request $request): Response
    {
        return $this->render('admin/users/profile.twig', [
            'seo' => $this->seo('Il mio profilo')->withNoindex(),
            'user' => $this->currentUser(),
            'regolaPassword' => PasswordPolicy::description(
                $this->config->int('security.password.min_length', PasswordPolicy::MIN_LENGTH),
            ),
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $user = $this->currentUser();

        $validator = Validator::make($request->all())
            ->required('name', 'Il nome')->max('name', 120, 'Il nome')
            ->required('email', 'L indirizzo email')->email('email', 'L indirizzo email');

        if ($validator->fails()) {
            return $this->backWithErrors($request, $request->all(), $validator->errors(), $this->url->route('admin.profile'));
        }

        $data = $validator->validatedData();

        $result = $this->adminUsers->updateProfile(
            $user->id,
            (string) $data['name'],
            (string) $data['email'],
            $user,
        );

        $result->successful ? $this->success($result->message) : $this->error($result->message);

        return $this->redirectToRoute('admin.profile');
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->currentUser();
        $minLength = $this->config->int('security.password.min_length', PasswordPolicy::MIN_LENGTH);

        $validator = Validator::make($request->all())
            ->required('current_password', 'La password attuale')
            ->password('new_password', 'La nuova password', $minLength)
            ->matches('new_password_confirmation', 'new_password', 'La conferma della password');

        if ($validator->fails()) {
            $this->session->flashErrors($validator->errors());
            $this->error('Controlla i campi segnalati e riprova.');

            return $this->redirectToRoute('admin.profile');
        }

        $result = $this->passwordReset->changeOwnPassword(
            $user->id,
            (string) $request->post('current_password'),
            (string) $request->post('new_password'),
        );

        if ($result->failed()) {
            $this->error($result->message);

            return $this->redirectToRoute('admin.profile');
        }

        // Il cambio password invalida le altre sessioni, compresa la corrente:
        // meglio riportare l utente al login con un messaggio chiaro.
        $this->auth->logout();
        $this->success('Password aggiornata. Accedi con le nuove credenziali.');

        return $this->redirectToRoute('admin.login');
    }
}
