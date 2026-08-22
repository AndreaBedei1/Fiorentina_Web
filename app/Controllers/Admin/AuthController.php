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
use App\Services\AdminUserService;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\PasswordResetService;
use App\Validation\PasswordPolicy;
use App\Validation\Validator;

/**
 * Accesso all'area riservata.
 *
 * Non esiste alcuna registrazione pubblica: gli account nascono solo da un
 * invito creato da un super amministratore. Non c'e una rotta /admin/register,
 * e non deve essere aggiunta.
 */
final class AuthController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly PasswordResetService $passwordReset,
        private readonly AdminUserService $adminUsers,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    // -----------------------------------------------------------------------
    //  Accesso
    // -----------------------------------------------------------------------

    public function showLogin(Request $request): Response
    {
        if ($this->auth->check()) {
            return $this->redirectToRoute('admin.home');
        }

        return $this->render('admin/auth/login.twig', [
            'seo' => $this->seo('Accesso area riservata')->withNoindex(),
        ]);
    }

    public function login(Request $request): Response
    {
        $email = $request->string('email');
        $password = (string) $request->post('password', '');

        if ($email === '' || $password === '') {
            $this->error('Inserisci email e password.');
            $this->session->flashInput(['email' => $email]);

            return $this->redirectToRoute('admin.login');
        }

        $result = $this->auth->attempt($email, $password);

        if ($result->failed()) {
            $this->audit->log(
                $result->isThrottled() ? AuditLogger::LOGIN_BLOCKED : AuditLogger::LOGIN_FAILED,
                'user',
                null,
                sprintf('Tentativo di accesso non riuscito per %s', \App\Core\Support\Str::maskEmail($email)),
                ['reason' => $result->reason],
            );

            $this->error($result->message());
            $this->session->flashInput(['email' => $email]);

            return $this->redirectToRoute('admin.login');
        }

        $this->audit->log(AuditLogger::LOGIN, 'user', $result->user?->id, 'Accesso effettuato');

        return $this->redirect($this->intendedUrl());
    }

    public function logout(Request $request): Response
    {
        $this->audit->log(AuditLogger::LOGOUT, 'user', $this->auth->id(), 'Disconnessione');
        $this->auth->logout();
        $this->success('Sessione chiusa.');

        return $this->redirectToRoute('admin.login');
    }

    // -----------------------------------------------------------------------
    //  Password dimenticata
    // -----------------------------------------------------------------------

    public function showForgotPassword(Request $request): Response
    {
        return $this->render('admin/auth/forgot-password.twig', [
            'seo' => $this->seo('Password dimenticata')->withNoindex(),
        ]);
    }

    public function sendResetLink(Request $request): Response
    {
        $email = $request->string('email');

        $this->passwordReset->requestReset($email, $request->ip());

        /*
         * Messaggio identico in ogni caso, anche quando l'indirizzo non esiste:
         * altrimenti il modulo diventerebbe uno strumento per capire quali
         * email appartengono allo staff del gruppo.
         */
        $this->info('Se l indirizzo corrisponde a un account attivo, riceverai a breve un messaggio con le istruzioni.');

        return $this->redirectToRoute('admin.login');
    }

    public function showResetForm(Request $request): Response
    {
        $token = (string) $request->route('token');

        if (! $this->passwordReset->tokenIsValid($token)) {
            $this->error('Il link non è più valido. Richiedi una nuova reimpostazione.');

            return $this->redirectToRoute('admin.password.forgot');
        }

        return $this->render('admin/auth/reset-password.twig', [
            'seo' => $this->seo('Nuova password')->withNoindex(),
            'token' => $token,
            'regolaPassword' => PasswordPolicy::description(
                $this->config->int('security.password.min_length', PasswordPolicy::MIN_LENGTH),
            ),
        ]);
    }

    public function resetPassword(Request $request): Response
    {
        $token = (string) $request->route('token');
        $minLength = $this->config->int('security.password.min_length', PasswordPolicy::MIN_LENGTH);

        $validator = Validator::make($request->all())
            ->password('password', 'La nuova password', $minLength)
            ->matches('password_confirmation', 'password', 'La conferma della password');

        if ($validator->fails()) {
            $this->session->flashErrors($validator->errors());
            $this->error('Controlla i campi segnalati e riprova.');

            return $this->redirectToRoute('admin.password.reset.form', ['token' => $token]);
        }

        $result = $this->passwordReset->resetPassword($token, (string) $request->post('password'));

        if ($result->failed()) {
            $this->error($result->message);

            return $this->redirectToRoute('admin.password.forgot');
        }

        $this->success($result->message);

        return $this->redirectToRoute('admin.login');
    }

    // -----------------------------------------------------------------------
    //  Accettazione invito
    // -----------------------------------------------------------------------

    public function showInviteForm(Request $request): Response
    {
        $token = (string) $request->route('token');
        $invite = $this->adminUsers->findInviteByToken($token);

        if ($invite === null) {
            $this->error('Il link di invito non e valido o e scaduto. Chiedi un nuovo invito a un super amministratore.');

            return $this->redirectToRoute('admin.login');
        }

        return $this->render('admin/auth/accept-invite.twig', [
            'seo' => $this->seo('Attiva il tuo account')->withNoindex(),
            'token' => $token,
            'invite' => $invite,
            'regolaPassword' => PasswordPolicy::description(
                $this->config->int('security.password.min_length', PasswordPolicy::MIN_LENGTH),
            ),
        ]);
    }

    public function acceptInvite(Request $request): Response
    {
        $token = (string) $request->route('token');
        $minLength = $this->config->int('security.password.min_length', PasswordPolicy::MIN_LENGTH);

        $validator = Validator::make($request->all())
            ->password('password', 'La password', $minLength)
            ->matches('password_confirmation', 'password', 'La conferma della password');

        if ($validator->fails()) {
            $this->session->flashErrors($validator->errors());
            $this->error('Controlla i campi segnalati e riprova.');

            return $this->redirectToRoute('admin.invite.accept', ['token' => $token]);
        }

        $result = $this->adminUsers->acceptInvite($token, (string) $request->post('password'));

        if ($result->failed()) {
            $this->error($result->message);

            return $this->redirectToRoute('admin.login');
        }

        $this->success($result->message);

        return $this->redirectToRoute('admin.login');
    }
}
