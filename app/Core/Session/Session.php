<?php

declare(strict_types=1);

namespace App\Core\Session;

/**
 * Wrapper sulle sessioni native PHP.
 *
 * Su hosting condiviso (Aruba Easy) le sessioni native su file sono la scelta
 * più robusta: nessun demone aggiuntivo, nessun Redis. Qui centralizziamo i
 * parametri di sicurezza del cookie, la rigenerazione dell'ID e i flash message.
 */
final class Session
{
    private const FLASH_KEY = '_flash';
    private const FLASH_NEXT_KEY = '_flash_next';
    private const OLD_INPUT_KEY = '_old_input';
    private const ERRORS_KEY = '_errors';

    private bool $started = false;

    /**
     * @param string $savePath Directory dove salvare i file di sessione. Tenerli
     *                         fuori dalla cartella pubblica evita che un errore di
     *                         configurazione li renda scaricabili.
     */
    public function __construct(
        private readonly string $name = 'baraonda_session',
        private readonly int $lifetimeMinutes = 120,
        private readonly bool $secure = false,
        private readonly string $sameSite = 'Lax',
        private readonly string $savePath = '',
        private readonly string $cookiePath = '/',
    ) {
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            $this->rotateFlash();

            return;
        }

        if (headers_sent()) {
            // In CLI/test non c'e una sessione HTTP: lavoriamo comunque in memoria.
            $this->started = true;

            return;
        }

        if ($this->savePath !== '' && is_dir($this->savePath)) {
            session_save_path($this->savePath);
        }

        session_name($this->name);

        session_set_cookie_params([
            'lifetime' => 0, // cookie di sessione: la scadenza la gestiamo lato server
            'path' => $this->cookiePath,
            'domain' => '',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => $this->sameSite,
        ]);

        // Rifiuta gli ID di sessione non generati dal server (session fixation).
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) ($this->lifetimeMinutes * 60));

        session_start();

        $this->started = true;
        $this->rotateFlash();
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $_SESSION ?? [];
    }

    /**
     * Rigenera l'ID di sessione mantenendo i dati.
     *
     * Da chiamare a ogni cambio di livello di privilegio (login, logout, cambio
     * password): senza questo passaggio un ID catturato prima del login resta
     * valido dopo.
     */
    public function regenerate(bool $deleteOldSession = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && ! headers_sent()) {
            session_regenerate_id($deleteOldSession);
        }
    }

    /** Svuota completamente la sessione e ne genera una nuova. */
    public function invalidate(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE && ! headers_sent()) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name() ?: $this->name, '', [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? $this->sameSite,
                ]);
            }

            session_destroy();
            session_start();
            session_regenerate_id(true);
        }
    }

    // -----------------------------------------------------------------------
    //  Flash message
    // -----------------------------------------------------------------------

    /**
     * I flash scritti in questa richiesta finiscono in FLASH_NEXT_KEY e
     * diventano leggibili nella successiva; quelli della richiesta precedente
     * vivono in FLASH_KEY e vengono scartati al giro dopo.
     */
    public function flash(string $type, string $message): void
    {
        $_SESSION[self::FLASH_NEXT_KEY][$type][] = $message;
    }

    /** @return list<string> */
    public function getFlash(string $type): array
    {
        return $_SESSION[self::FLASH_KEY][$type] ?? [];
    }

    /** @return array<string, list<string>> */
    public function allFlash(): array
    {
        return $_SESSION[self::FLASH_KEY] ?? [];
    }

    public function hasFlash(string $type): bool
    {
        return ! empty($_SESSION[self::FLASH_KEY][$type]);
    }

    /** @param array<string, mixed> $input */
    public function flashInput(array $input): void
    {
        // Non riproponiamo mai le password nei form ripopolati.
        foreach (['password', 'password_confirmation', 'current_password', 'new_password'] as $sensitive) {
            unset($input[$sensitive]);
        }

        $_SESSION[self::FLASH_NEXT_KEY][self::OLD_INPUT_KEY] = $input;
    }

    public function old(string $key, mixed $default = null): mixed
    {
        return $_SESSION[self::FLASH_KEY][self::OLD_INPUT_KEY][$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function allOld(): array
    {
        return $_SESSION[self::FLASH_KEY][self::OLD_INPUT_KEY] ?? [];
    }

    /** @param array<string, list<string>|string> $errors */
    public function flashErrors(array $errors): void
    {
        $_SESSION[self::FLASH_NEXT_KEY][self::ERRORS_KEY] = $errors;
    }

    /** @return array<string, list<string>|string> */
    public function errors(): array
    {
        return $_SESSION[self::FLASH_KEY][self::ERRORS_KEY] ?? [];
    }

    public function error(string $field): ?string
    {
        $errors = $this->errors();

        if (! isset($errors[$field])) {
            return null;
        }

        $value = $errors[$field];

        return is_array($value) ? ($value[0] ?? null) : $value;
    }

    private function rotateFlash(): void
    {
        $_SESSION[self::FLASH_KEY] = $_SESSION[self::FLASH_NEXT_KEY] ?? [];
        unset($_SESSION[self::FLASH_NEXT_KEY]);
    }

    /**
     * Mantiene i flash correnti anche per la richiesta successiva.
     * Serve quando un middleware redirige prima che la view li abbia mostrati.
     */
    public function reflash(): void
    {
        $current = $_SESSION[self::FLASH_KEY] ?? [];
        $next = $_SESSION[self::FLASH_NEXT_KEY] ?? [];

        $_SESSION[self::FLASH_NEXT_KEY] = array_merge_recursive($current, $next);
    }
}
