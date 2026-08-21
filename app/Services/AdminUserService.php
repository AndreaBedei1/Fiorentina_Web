<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Routing\UrlGenerator;
use App\Core\Security\Hash;
use App\Core\Security\TokenGenerator;
use App\Models\User;
use App\Repositories\AuthTokenRepository;
use App\Repositories\UserRepository;
use App\Services\Mail\MailService;

/**
 * Gestione degli account amministratore.
 *
 * Concentra qui le regole che non devono poter essere aggirate da nessuna
 * schermata: nessuna registrazione pubblica, solo un SUPER_ADMIN può creare o
 * modificare account, e non si può mai rimanere senza super amministratori
 * attivi (sarebbe un sito impossibile da riprendere in mano).
 */
final class AdminUserService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthTokenRepository $tokens,
        private readonly Hash $hash,
        private readonly TokenGenerator $tokenGenerator,
        private readonly MailService $mail,
        private readonly AuditLogger $audit,
        private readonly UrlGenerator $url,
        private readonly Config $config,
        private readonly SettingsService $settings,
    ) {
    }

    // -----------------------------------------------------------------------
    //  Invito
    // -----------------------------------------------------------------------

    /**
     * Crea un account in attesa e invia l'invito.
     *
     * L'account nasce senza password: sara la persona invitata a sceglierla
     * tramite il link monouso. Nessuno, nemmeno il super amministratore che
     * invita, conosce mai la password altrui.
     */
    public function invite(string $name, string $email, string $role, User $invitedBy): AdminActionResult
    {
        $email = mb_strtolower(trim($email));

        if ($this->users->emailExists($email)) {
            return AdminActionResult::failure('Esiste già un amministratore con questo indirizzo email.');
        }

        if (! in_array($role, [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], true)) {
            return AdminActionResult::failure('Ruolo non valido.');
        }

        $userId = $this->users->create([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_PENDING,
            'password_hash' => null,
            'created_by' => $invitedBy->id,
        ]);

        $token = $this->tokenGenerator->generate();
        $ttl = $this->config->int('security.password.invite_token_ttl', 10080);

        $this->tokens->createInvite($email, $name, $role, $token->hash, $userId, $invitedBy->id, $ttl);

        $link = $this->url->absoluteRoute('admin.invite.accept', ['token' => $token->plain]);

        $sent = $this->mail->send(
            $email,
            'Sei stato invitato a gestire il sito di ' . $this->settings->string('site_group_name', 'Baraonda Fiorentina'),
            'emails/admin-invite.twig',
            [
                'name' => $name,
                'role_label' => $role === User::ROLE_SUPER_ADMIN ? 'Super amministratore' : 'Amministratore',
                'inviter' => $invitedBy->name,
                'link' => $link,
                'expires_hours' => (int) round($ttl / 60),
            ],
        );

        $this->audit->log(
            AuditLogger::ADMIN_INVITED,
            'user',
            $userId,
            sprintf('Invito inviato a %s (%s)', $name, $email),
            ['role' => $role, 'email_sent' => $sent],
        );

        return AdminActionResult::success(
            $sent
                ? 'Invito inviato correttamente a ' . $email . '.'
                : 'Account creato, ma l\'invio dell\'email non e riuscito. Consegna il link manualmente.',
            ['user_id' => $userId, 'link' => $link, 'email_sent' => $sent],
        );
    }

    /** @return array<string, mixed>|null Invito valido corrispondente al token. */
    public function findInviteByToken(string $plainToken): ?array
    {
        return $this->tokens->findValidInvite(TokenGenerator::hash($plainToken));
    }

    /** Completa l'invito: imposta la password e attiva l'account. */
    public function acceptInvite(string $plainToken, string $password): AdminActionResult
    {
        $invite = $this->findInviteByToken($plainToken);

        if ($invite === null) {
            return AdminActionResult::failure('Il link di invito non è valido o e scaduto. Chiedi un nuovo invito.');
        }

        $userId = (int) $invite['user_id'];
        $user = $this->users->find($userId);

        if ($user === null) {
            return AdminActionResult::failure('L\'account collegato a questo invito non esiste più.');
        }

        $this->users->update($userId, [
            'password_hash' => $this->hash->make($password),
            'password_changed_at' => date('Y-m-d H:i:s'),
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->tokens->markInviteAccepted((int) $invite['id']);

        $this->audit->log(
            AuditLogger::ADMIN_ACTIVATED,
            'user',
            $userId,
            sprintf('%s ha completato l\'attivazione dell\'account', $user->name),
            [],
            $user,
        );

        return AdminActionResult::success('Account attivato. Ora puoi accedere.', ['user_id' => $userId]);
    }

    public function resendInvite(int $userId, User $actor): AdminActionResult
    {
        $user = $this->users->find($userId);

        if ($user === null) {
            return AdminActionResult::failure('Amministratore non trovato.');
        }

        if (! $user->isPending()) {
            return AdminActionResult::failure('Questo account è già attivo: non serve un nuovo invito.');
        }

        $this->tokens->revokeInvitesForUser($userId);

        return $this->invite($user->name, $user->email, $user->role, $actor);
    }

    // -----------------------------------------------------------------------
    //  Stato e ruolo
    // -----------------------------------------------------------------------

    public function block(int $targetId, User $actor): AdminActionResult
    {
        $guard = $this->guardTarget($targetId, $actor, 'bloccare');

        if ($guard !== null) {
            return $guard;
        }

        if ($this->users->isLastActiveSuperAdmin($targetId)) {
            return AdminActionResult::failure(
                'Non puoi bloccare l\'ultimo super amministratore attivo: il sito resterebbe senza nessuno in grado di gestirlo.'
            );
        }

        $target = $this->users->find($targetId);
        $this->users->block($targetId);

        $this->audit->log(
            AuditLogger::ADMIN_BLOCKED,
            'user',
            $targetId,
            sprintf('Account di %s bloccato', $target?->name ?? '?'),
            ['target_email' => $target?->email],
        );

        return AdminActionResult::success('Account bloccato. Le sue sessioni attive sono state chiuse.');
    }

    public function unblock(int $targetId, User $actor): AdminActionResult
    {
        $guard = $this->guardTarget($targetId, $actor, 'sbloccare', allowSelf: false);

        if ($guard !== null) {
            return $guard;
        }

        $target = $this->users->find($targetId);

        if ($target === null) {
            return AdminActionResult::failure('Amministratore non trovato.');
        }

        // Un account che non ha mai scelto la password torna "in attesa di invito".
        $this->users->update($targetId, [
            'status' => $target->passwordHash === null ? User::STATUS_PENDING : User::STATUS_ACTIVE,
        ]);

        $this->audit->log(
            AuditLogger::ADMIN_UNBLOCKED,
            'user',
            $targetId,
            sprintf('Account di %s sbloccato', $target->name),
        );

        return AdminActionResult::success('Account sbloccato.');
    }

    public function changeRole(int $targetId, string $newRole, User $actor): AdminActionResult
    {
        if (! in_array($newRole, [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], true)) {
            return AdminActionResult::failure('Ruolo non valido.');
        }

        $guard = $this->guardTarget($targetId, $actor, 'modificare il ruolo di');

        if ($guard !== null) {
            return $guard;
        }

        $target = $this->users->find($targetId);

        if ($target === null) {
            return AdminActionResult::failure('Amministratore non trovato.');
        }

        if ($target->role === $newRole) {
            return AdminActionResult::success('Il ruolo era già impostato su questo valore.');
        }

        if ($newRole === User::ROLE_ADMIN && $this->users->isLastActiveSuperAdmin($targetId)) {
            return AdminActionResult::failure(
                'Non puoi declassare l\'ultimo super amministratore attivo. Nominane prima un altro.'
            );
        }

        $this->users->changeRole($targetId, $newRole);

        $this->audit->log(
            AuditLogger::ADMIN_ROLE_CHANGED,
            'user',
            $targetId,
            sprintf('Ruolo di %s modificato da %s a %s', $target->name, $target->role, $newRole),
            ['from' => $target->role, 'to' => $newRole],
        );

        return AdminActionResult::success('Ruolo aggiornato.');
    }

    public function delete(int $targetId, User $actor): AdminActionResult
    {
        $guard = $this->guardTarget($targetId, $actor, 'eliminare');

        if ($guard !== null) {
            return $guard;
        }

        if ($this->users->isLastActiveSuperAdmin($targetId)) {
            return AdminActionResult::failure(
                'Non puoi eliminare l\'ultimo super amministratore attivo.'
            );
        }

        $target = $this->users->find($targetId);
        $this->users->softDeleteUser($targetId);
        $this->tokens->revokeInvitesForUser($targetId);

        $this->audit->log(
            AuditLogger::ADMIN_DELETED,
            'user',
            $targetId,
            sprintf('Account di %s disattivato', $target?->name ?? '?'),
            ['target_email' => $target?->email],
        );

        return AdminActionResult::success(
            'Account disattivato. Resta nel registro delle attività, come richiesto dalla tracciabilita.'
        );
    }

    /** Aggiorna nome, email e telefono di un amministratore. */
    public function updateProfile(int $targetId, string $name, string $email, ?string $phone, User $actor): AdminActionResult
    {
        $email = mb_strtolower(trim($email));

        if ($this->users->emailExists($email, $targetId)) {
            return AdminActionResult::failure('Un altro amministratore utilizza già questo indirizzo email.');
        }

        if ($targetId !== $actor->id && ! $actor->isSuperAdmin()) {
            return AdminActionResult::failure('Solo un super amministratore può modificare altri account.');
        }

        $this->users->update($targetId, ['name' => $name, 'email' => $email, 'phone' => $phone]);

        $this->audit->log(AuditLogger::ADMIN_UPDATED, 'user', $targetId, sprintf('Dati di %s aggiornati', $name));

        return AdminActionResult::success('Dati aggiornati.');
    }

    /**
     * Verifiche comuni a tutte le azioni su un altro account.
     *
     * @param bool $allowSelf Se false, l'azione su se stessi viene rifiutata.
     */
    private function guardTarget(int $targetId, User $actor, string $verb, bool $allowSelf = false): ?AdminActionResult
    {
        if (! $actor->isSuperAdmin()) {
            return AdminActionResult::failure('Solo un super amministratore può ' . $verb . ' un account.');
        }

        if ($targetId === $actor->id && ! $allowSelf) {
            return AdminActionResult::failure('Non puoi ' . $verb . ' il tuo stesso account.');
        }

        if ($this->users->find($targetId) === null) {
            return AdminActionResult::failure('Amministratore non trovato.');
        }

        return null;
    }
}
