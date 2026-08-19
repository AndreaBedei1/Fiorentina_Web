<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Wrapper immutabile attorno alle superglobali.
 *
 * Nessun controller legge mai `$_GET`/`$_POST`/`$_FILES` direttamente: passando
 * da qui otteniamo normalizzazione (trim, stringhe vuote -> null), accesso
 * tipizzato e un unico punto in cui gestire method spoofing e proxy fidati.
 */
final class Request
{
    /** @var array<string, string> */
    private array $routeParameters = [];

    private ?string $matchedRouteName = null;

    /**
     * @param array<string, mixed>          $query
     * @param array<string, mixed>          $body
     * @param array<string, mixed>          $server
     * @param array<string, mixed>          $cookies
     * @param array<string, UploadedFile[]> $files
     */
    public function __construct(
        private readonly array $query,
        private readonly array $body,
        private readonly array $server,
        private readonly array $cookies,
        private readonly array $files,
        private readonly string $rawBody = '',
    ) {
    }

    public static function capture(): self
    {
        return new self(
            query: $_GET,
            body: $_POST,
            server: $_SERVER,
            cookies: $_COOKIE,
            files: UploadedFile::normalize($_FILES),
            rawBody: (string) file_get_contents('php://input'),
        );
    }

    /**
     * Costruttore di comodo per i test: nessuna dipendenza dalle superglobali.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @param array<string, mixed> $server
     */
    public static function create(
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        array $server = [],
        array $cookies = [],
        array $files = [],
    ): self {
        return new self(
            query: $query,
            body: $body,
            server: array_merge([
                'REQUEST_METHOD' => strtoupper($method),
                'REQUEST_URI' => $path,
                'HTTP_HOST' => 'localhost',
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTPS' => 'off',
            ], $server),
            cookies: $cookies,
            files: $files,
        );
    }

    // -----------------------------------------------------------------------
    //  Metodo e URI
    // -----------------------------------------------------------------------

    /** Metodo HTTP effettivo, tenendo conto dello spoofing via campo `_method`. */
    public function method(): string
    {
        $method = $this->realMethod();

        if ($method === 'POST') {
            $spoofed = strtoupper((string) ($this->body['_method'] ?? ''));

            if (in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $spoofed;
            }
        }

        return $method;
    }

    public function realMethod(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function isGet(): bool
    {
        return $this->isMethod('GET');
    }

    public function isPost(): bool
    {
        return $this->realMethod() === 'POST';
    }

    /** Path della richiesta, normalizzato: sempre con `/` iniziale, senza `/` finale. */
    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function queryString(): string
    {
        return (string) ($this->server['QUERY_STRING'] ?? '');
    }

    public function fullPath(): string
    {
        $query = $this->queryString();

        return $this->path() . ($query !== '' ? '?' . $query : '');
    }

    public function scheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));

        if ($https !== '' && $https !== 'off') {
            return true;
        }

        if ((int) ($this->server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        // Aruba, come molti hosting condivisi, termina il TLS su un frontend.
        return strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function host(): string
    {
        $host = (string) ($this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? 'localhost');

        // Difesa contro Host header injection: teniamo solo caratteri legittimi.
        return preg_replace('/[^A-Za-z0-9\-.:\[\]]/', '', $host) ?: 'localhost';
    }

    public function fullUrl(): string
    {
        return $this->scheme() . '://' . $this->host() . $this->fullPath();
    }

    public function url(): string
    {
        return $this->scheme() . '://' . $this->host() . $this->path();
    }

    public function ip(): string
    {
        $trustedProxies = (int) ($_ENV['TRUSTED_PROXIES'] ?? 0);

        if ($trustedProxies > 0) {
            $forwarded = (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? '');

            if ($forwarded !== '') {
                $candidates = array_map('trim', explode(',', $forwarded));
                $candidate = $candidates[max(0, count($candidates) - $trustedProxies)] ?? '';

                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');

        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function referer(): string
    {
        return (string) ($this->server['HTTP_REFERER'] ?? '');
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($this->server[$key]) ? (string) $this->server[$key] : $default;
    }

    public function isAjax(): bool
    {
        return strtolower((string) $this->header('X-Requested-With')) === 'xmlhttprequest';
    }

    public function expectsJson(): bool
    {
        if ($this->isAjax()) {
            return true;
        }

        return str_contains(strtolower((string) $this->header('Accept', '')), 'application/json');
    }

    // -----------------------------------------------------------------------
    //  Input
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** Stringa normalizzata: trim e rimozione dei byte di controllo. */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key);

        if (is_array($value) || $value === null) {
            return $default;
        }

        $value = (string) $value;
        $value = str_replace("\0", '', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        return trim($value);
    }

    /** Come string(), ma restituisce null quando il campo e assente o vuoto. */
    public function nullableString(string $key): ?string
    {
        $value = $this->string($key);

        return $value === '' ? null : $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : null;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->input($key);

        if (is_string($value)) {
            // Accettiamo sia "12,50" (uso italiano) sia "12.50".
            $value = str_replace([' ', ','], ['', '.'], $value);
        }

        return is_numeric($value) ? (float) $value : $default;
    }

    /** Le checkbox non spuntate non vengono inviate: assenza significa false. */
    public function bool(string $key): bool
    {
        return filter_var($this->input($key, false), FILTER_VALIDATE_BOOL);
    }

    /** @return array<array-key, mixed> */
    public function array(string $key): array
    {
        $value = $this->input($key, []);

        return is_array($value) ? $value : [];
    }

    /** @return list<int> */
    public function intList(string $key): array
    {
        return array_values(array_filter(
            array_map(static fn ($v) => is_numeric($v) ? (int) $v : null, $this->array($key)),
            static fn (?int $v) => $v !== null,
        ));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    public function filled(string $key): bool
    {
        return $this->string($key) !== '';
    }

    /** @param list<string> $keys @return array<string, mixed> */
    public function only(array $keys): array
    {
        $all = $this->all();

        return array_intersect_key($all, array_flip($keys));
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    // -----------------------------------------------------------------------
    //  File
    // -----------------------------------------------------------------------

    public function file(string $key): ?UploadedFile
    {
        return $this->files[$key][0] ?? null;
    }

    /** @return list<UploadedFile> */
    public function fileList(string $key): array
    {
        return array_values(array_filter(
            $this->files[$key] ?? [],
            static fn (UploadedFile $file) => ! $file->isEmpty(),
        ));
    }

    public function hasFile(string $key): bool
    {
        return $this->file($key) !== null && ! $this->file($key)->isEmpty();
    }

    // -----------------------------------------------------------------------
    //  Parametri di rotta (popolati dal Router dopo il match)
    // -----------------------------------------------------------------------

    /** @param array<string, string> $parameters */
    public function setRouteParameters(array $parameters, ?string $routeName = null): void
    {
        $this->routeParameters = $parameters;
        $this->matchedRouteName = $routeName;
    }

    public function route(string $key, ?string $default = null): ?string
    {
        return $this->routeParameters[$key] ?? $default;
    }

    public function routeInt(string $key, int $default = 0): int
    {
        $value = $this->routeParameters[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<string, string> */
    public function routeParameters(): array
    {
        return $this->routeParameters;
    }

    public function routeName(): ?string
    {
        return $this->matchedRouteName;
    }
}
