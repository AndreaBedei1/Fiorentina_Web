<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Routing\UrlGenerator;
use App\Core\Security\Hash;
use App\Core\Security\TokenGenerator;
use App\Repositories\AuthTokenRepository;
use App\Repositories\UserRepository;
use App\Services\Mail\MailService;

/**
 * Recupero della password degli amministratori.
 *
 * Principio guida: la pagina "password dimenticata" risponde sempre allo stesso
 * modo, che l'indirizzo esista o no. Un messaggio differenziato trasformerebbe
 * il modulo in uno strumento per scoprire quali email appartengono allo staff.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthTokenRepository $tokens,
        private readonly Hash $hash,
        private readonly TokenGenerator $tokenGenerator,
        private readonly MailService $mail,
        private readonly AuditLogger $audit,
        private readonly RateLimiter $limiter,
        private readonly UrlGenerator $url,
        private readonly Config $config,
    ) {
    }

    /**
     * Avvia il recupero.
     *
     * Restituisce sempre true: il chiamante mostra un messaggio neutro e non
     * può, nemmeno volendo, rivelare l'esito reale.
     */
    public function requestReset(string $email, string $ip): bool
    {
        $email = mb_strtolower(trim($email));

        $maxAttempts = $this->config->int('security.rate_limits.password_reset.max_attempts', 5);
        $decay = $this->config->int('security.rate_limits.password_reset.decay_minutes', 60);

        if ($this->limiter->tooManyAttempts('password_reset', $ip, $maxAttempts)) {
            return true;
        }

        $this->limiter->hit('password_reset', $ip, $decay);

        $user = $this->users->findByEmail($email);

        if ($user === null || ! $user->isActive()) {
            return true;
        }

        $token = $this->tokenGenerator->generate();
        $ttl = $this->config->int('security.password.reset_token_ttl', 60);

        $this->tokens->createPasswordReset($user->id, $token->hash, $ip, $ttl);

        $this->mail->send(
            $user->email,
            'Reimposta la password del pannello Baraonda Fiorentina',
            'emails/password-reset.twig',
            [
                'name' => $user->firstName(),
                'link' => $this->url->absoluteRoute('admin.password.reset.form', ['token' => $token->plain]),
                'expires_minutes' => $ttl,
                'ip' => $ip,
            ],
        );

        $this->audit->log(
            AuditLogger::PASSWORD_RESET_REQUESTED,
            'user',
            $user->id,
            'Richiesta di reimpostazione password',
            ['ip' => $ip],
            $user,
        );

        return true;
    }

    public function tokenIsValid(string $plainToken): bool
    {
        return $this->tokens->findValidPasswordReset(TokenGenerator::hash($plainToken)) !== null;
    }

    /** Completa il reset impostando la nuova password. */
    public function resetPassword(string $plainToken, string $newPassword): AdminActionResult
    {
        $record = $this->tokens->findValidPasswordReset(TokenGenerator::hash($plainToken));

        if ($record === null) {
            return AdminActionResult::failure(
                'Il link non è più valido. I link di reimpostazione scadono dopo poco tempo e valgono una sola volta.'
            );
        }

        $user = $this->users->find((int) $record['user_id']);

        if ($user === null || ! $user->isActive()) {
            return AdminActionResult::failure('L\'account collegato non e attivo.');
        }

        // updatePassword aggiorna anche sessions_valid_after: tutte le sessioni
        // aperte altrove decadono, che e il comportamento atteso dopo un reset.
        $this->users->updatePassword($user->id, $this->hash->make($newPassword));
        $this->tokens->markPasswordResetUsed((int) $record['id']);

        $this->audit->log(
            AuditLogger::PASSWORD_RESET_COMPLETED,
            'user',
            $user->id,
            'Password reimpostata tramite link di recupero',
            [],
            $user,
        );

        return AdminActionResult::success('Password aggiornata. Ora puoi accedere con le nuove credenziali.');
    }

    /** Cambio password dall'area riservata, con conferma di quella attuale. */
    public function changeOwnPassword(int $userId, string $currentPassword, string $newPassword): AdminActionResult
    {
        $user = $this->users->find($userId);

        if ($user === null) {
            return AdminActionResult::failure('Account non trovato.');
        }

        if (! $this->hash->verify($currentPassword, (string) $user->passwordHash)) {
            return AdminActionResult::failure('La password attuale non e corretta.');
        }

        $this->users->updatePassword($userId, $this->hash->make($newPassword));

        $this->audit->log(AuditLogger::PASSWORD_CHANGED, 'user', $userId, 'Password modificata dall area riservata');

        return AdminActionResult::success('Password aggiornata.');
    }
}
