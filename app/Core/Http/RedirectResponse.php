<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Session\Session;

/**
 * Redirect HTTP.
 *
 * `to()` accetta solo path interni: gli URL assoluti passano da `away()`, che e
 * usata soltanto per destinazioni note e statiche. In questo modo un parametro
 * controllato dall'utente non può trasformarsi in open redirect.
 */
final class RedirectResponse extends Response
{
    private function __construct(string $location, int $status = 302)
    {
        parent::__construct('', $status, [
            'Location' => $location,
            // Un redirect dopo POST non deve restare in cache.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /** Redirect verso un percorso interno. Qualsiasi URL assoluto viene neutralizzato. */
    public static function to(string $path, int $status = 302): self
    {
        return new self(self::sanitizeInternalPath($path), $status);
    }

    /** Redirect verso un URL esterno noto (non deve mai derivare da input utente). */
    public static function away(string $url, int $status = 302): self
    {
        return new self($url, $status);
    }

    public static function back(Request $request, string $fallback = '/'): self
    {
        $referer = $request->referer();

        if ($referer !== '') {
            $host = parse_url($referer, PHP_URL_HOST);

            // Torniamo indietro solo se il referer appartiene allo stesso host.
            if ($host === null || $host === $request->host() || $host === parse_url($request->url(), PHP_URL_HOST)) {
                $path = (string) (parse_url($referer, PHP_URL_PATH) ?: '/');
                $query = parse_url($referer, PHP_URL_QUERY);

                return self::to($path . ($query ? '?' . $query : ''));
            }
        }

        return self::to($fallback);
    }

    /**
     * Normalizza un percorso di destinazione.
     *
     * Blocca gli URL assoluti (`https://evil.tld`) e i protocol-relative
     * (`//evil.tld`), che i browser trattano come esterni.
     */
    public static function sanitizeInternalPath(string $path): string
    {
        $path = trim(str_replace(["\r", "\n", "\0"], '', $path));

        if ($path === '') {
            return '/';
        }

        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $path) === 1) {
            return '/';
        }

        // Anche le barre rovesciate vanno bloccate: diversi browser le
        // normalizzano in barre normali, quindi "/\evil.tld" diventerebbe un
        // indirizzo esterno a tutti gli effetti.
        $normalized = str_replace('\\', '/', $path);

        if (str_starts_with($normalized, '//')) {
            return '/';
        }

        return str_starts_with($path, '/') ? $path : '/' . $path;
    }

    /** Aggiunge un messaggio flash da mostrare dopo il redirect. */
    public function with(Session $session, string $type, string $message): self
    {
        $session->flash($type, $message);

        return $this;
    }

    /** Ripopola il form dopo un errore di validazione. */
    public function withInput(Session $session, array $input, array $errors = []): self
    {
        $session->flashInput($input);

        if ($errors !== []) {
            $session->flashErrors($errors);
        }

        return $this;
    }
}
