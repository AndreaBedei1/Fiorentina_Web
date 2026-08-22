<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Security\Csrf;
use App\Core\Security\Hash;
use App\Core\Session\Session;
use App\Models\User;
use App\Repositories\AuthTokenRepository;
use App\Repositories\UserRepository;

/**
 * Autenticazione e autorizzazione degli amministratori.
 *
 * Ogni richiesta ricontrolla l'utente a database: è più lavoro rispetto a
 * fidarsi della sessione, ma e cio che rende immediato il blocco di un account.
 * Senza questa verifica, un amministratore bloccato resterebbe operativo fino
 * alla scadenza della sua sessione.
 */
final class AuthService
{
    private const SESSION_USER_ID = 'auth_user_id';
    private const SESSION_LOGIN_AT = 'auth_login_at';
    private const SESSION_LAST_ACTIVITY = 'auth_last_activity';
    private const SESSION_FINGERPRINT = 'auth_fingerprint';

    /** Permessi del ruolo ADMIN. Il SUPER_ADMIN li possiede tutti, più i propri. */
    private const ADMIN_PERMISSIONS = [
        'news.manage',
        'events.manage',
        'calendar.manage',
        'gallery.manage',
        'products.manage',
        'orders.manage',
        'organization.manage',
        'social.manage',
    ];

    /** Permessi riservati al SUPER_ADMIN. */
    private const SUPER_ADMIN_PERMISSIONS = [
        'admins.manage',
    ];

    private ?User $cachedUser = null;

    private bool $resolved = false;

    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthTokenRepository $tokens,
        private readonly Session $session,
        private readonly Hash $hash,
        private readonly Csrf $csrf,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
        private readonly Request $request,
    ) {
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    // -----------------------------------------------------------------------
    //  Accesso
    // -----------------------------------------------------------------------

    /**
     * Tenta l'autenticazione.
     *
     * I messaggi di errore sono volutamente generici: distinguere "email
     * inesistente" da "password errata" regalerebbe a un attaccante la
     * conferma di quali indirizzi corrispondono ad amministratori reali.
     */
    public function attempt(string $email, string $password): AuthResult
    {
        $email = mb_strtolower(trim($email));
        $ip = $this->request->ip();

        $maxAttempts = $this->config->int('security.rate_limits.login.max_attempts', 5);
        $decayMinutes = $this->config->int('security.rate_limits.login.decay_minutes', 15);

        if ($this->limiter->tooManyAttempts('login', $email . '|' . $ip, $maxAttempts)) {
            $seconds = $this->limiter->secondsUntilReset('login', $email . '|' . $ip);

            return AuthResult::throttled($seconds);
        }

        $user = $this->users->findByEmail($email);

        // La verifica avviene comunque, anche senza utente: senza questo passaggio
        // il tempo di risposta rivelerebbe quali email esistono.
        $passwordMatches = $this->hash->verify($password, $user?->passwordHash ?? '');

        if ($user === null || ! $passwordMatches) {
            $this->registerFailure($email, $ip);

            return AuthResult::invalidCredentials();
        }

        if ($user->isBlocked()) {
            $this->registerFailure($email, $ip);

            return AuthResult::blocked();
        }

        if (! $user->canLogin()) {
            $this->registerFailure($email, $ip);

            return AuthResult::inactive();
        }

        // Se i parametri di hashing sono cambiati, aggiorniamo l'hash adesso che
        // abbiamo la password in chiaro: e l'unico momento possibile.
        if ($this->hash->needsRehash((string) $user->passwordHash)) {
            $this->users->update($user->id, ['password_hash' => $this->hash->make($password)]);
        }

        $this->login($user);

        /*
         * Azzeriamo il contatore del blocco temporaneo, ma NON lo storico dei
         * tentativi: quello e una traccia di sicurezza, e cancellarla proprio
         * dopo un accesso riuscito significherebbe perdere l unica prova di un
         * attacco andato a buon fine.
         */
        $this->limiter->clear('login', $email . '|' . $ip);
        $this->tokens->recordLoginAttempt($email, $ip, true, $this->request->userAgent());

        return AuthResult::success($user);
    }

    /** Apre la sessione autenticata. Usato anche dopo l'accettazione di un invito. */
    public function login(User $user): void
    {
        // Rigenerare l'ID prima di scrivere i dati chiude la session fixation:
        // un ID ottenuto prima dell'accesso non diventa mai un ID autenticato.
        $this->session->regenerate();

        $this->session->put(self::SESSION_USER_ID, $user->id);
        $this->session->put(self::SESSION_LOGIN_AT, time());
        $this->session->put(self::SESSION_LAST_ACTIVITY, time());
        $this->session->put(self::SESSION_FINGERPRINT, $this->fingerprint());

        // Nuovo token CSRF a ogni cambio di privilegio.
        $this->csrf->regenerate();

        $this->cachedUser = $user;
        $this->resolved = true;
    }

    public function logout(): void
    {
        $this->session->invalidate();
        $this->csrf->regenerate();

        $this->cachedUser = null;
        $this->resolved = true;
    }

    private function registerFailure(string $email, string $ip): void
    {
        $decayMinutes = $this->config->int('security.rate_limits.login.decay_minutes', 15);

        $this->limiter->hit('login', $email . '|' . $ip, $decayMinutes);
        $this->tokens->recordLoginAttempt($email, $ip, false, $this->request->userAgent());
    }

    // -----------------------------------------------------------------------
    //  Utente corrente
    // -----------------------------------------------------------------------

    /**
     * Utente autenticato, oppure null.
     *
     * Verifica in ordine: presenza in sessione, coerenza dell'impronta del
     * browser, timeout di inattivita, esistenza e stato dell'account,
     * invalidazione forzata delle sessioni.
     */
    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->cachedUser;
        }

        $this->resolved = true;
        $this->cachedUser = null;

        $userId = $this->session->get(self::SESSION_USER_ID);

        if (! is_int($userId) && ! (is_string($userId) && ctype_digit($userId))) {
            return null;
        }

        if (! hash_equals((string) $this->session->get(self::SESSION_FINGERPRINT, ''), $this->fingerprint())) {
            $this->logout();

            return null;
        }

        $lifetimeMinutes = $this->config->int('session.lifetime', 120);
        $lastActivity = (int) $this->session->get(self::SESSION_LAST_ACTIVITY, 0);

        if ($lastActivity > 0 && (time() - $lastActivity) > $lifetimeMinutes * 60) {
            $this->logout();

            return null;
        }

        $user = $this->users->find((int) $userId);

        if ($user === null || ! $user->isActive()) {
            $this->logout();

            return null;
        }

        // Blocco o cambio password invalidano le sessioni aperte prima di quel momento.
        $loginAt = (int) $this->session->get(self::SESSION_LOGIN_AT, 0);

        if ($user->sessionsValidAfter !== null && $loginAt < $user->sessionsValidAfter->getTimestamp()) {
            $this->logout();

            return null;
        }

        $this->session->put(self::SESSION_LAST_ACTIVITY, time());

        return $this->cachedUser = $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): ?int
    {
        return $this->user()?->id;
    }

    public function isSuperAdmin(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    // -----------------------------------------------------------------------
    //  Autorizzazione
    // -----------------------------------------------------------------------

    public function can(string $permission): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return in_array($permission, self::ADMIN_PERMISSIONS, true);
    }

    public function cannot(string $permission): bool
    {
        return ! $this->can($permission);
    }

    /** @return list<string> */
    public static function permissionsFor(string $role): array
    {
        return $role === User::ROLE_SUPER_ADMIN
            ? [...self::ADMIN_PERMISSIONS, ...self::SUPER_ADMIN_PERMISSIONS]
            : self::ADMIN_PERMISSIONS;
    }

    /** @return list<string> */
    public static function allPermissions(): array
    {
        return [...self::ADMIN_PERMISSIONS, ...self::SUPER_ADMIN_PERMISSIONS];
    }

    /**
     * Impronta del browser: solo lo user agent.
     *
     * L'indirizzo IP è escluso di proposito. Sulle reti mobili cambia di
     * continuo e includerlo scollegherebbe gli amministratori a meta lavoro,
     * senza un guadagno di sicurezza paragonabile al fastidio.
     */
    private function fingerprint(): string
    {
        return hash('sha256', $this->request->userAgent() . '|' . $this->config->string('app.key'));
    }
}
