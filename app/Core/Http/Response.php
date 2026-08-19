<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Risposta HTTP. I controller restituiscono sempre un oggetto Response: nessun
 * `echo` sparso, nessun `header()` chiamato a meta pagina.
 */
class Response
{
    /** @var array<string, list<string>> */
    protected array $headers = [];

    /** @var list<array{name: string, value: string, options: array<string, mixed>}> */
    protected array $cookies = [];

    public function __construct(
        protected string $content = '',
        protected int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->header((string) $name, (string) $value);
        }
    }

    public static function make(string $content = '', int $status = 200, array $headers = []): static
    {
        return new static($content, $status, $headers);
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function text(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public static function xml(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public static function noContent(int $status = 204): self
    {
        return new self('', $status);
    }

    public function header(string $name, string $value, bool $replace = true): static
    {
        $key = $this->normalizeHeaderName($name);

        if ($replace || ! isset($this->headers[$key])) {
            $this->headers[$key] = [$value];
        } else {
            $this->headers[$key][] = $value;
        }

        return $this;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->header((string) $name, (string) $value);
        }

        return $this;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$this->normalizeHeaderName($name)]);
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$this->normalizeHeaderName($name)][0] ?? null;
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @param array<string, mixed> $options */
    public function cookie(string $name, string $value, array $options = []): static
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Invia la risposta al client. Unico punto del progetto che chiama header()/echo. */
    public function send(): void
    {
        if (! headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $values) {
                $first = true;

                foreach ($values as $value) {
                    header($name . ': ' . $value, $first);
                    $first = false;
                }
            }

            foreach ($this->cookies as $cookie) {
                setcookie($cookie['name'], $cookie['value'], $cookie['options']);
            }
        }

        // 204 e 304 non devono avere corpo.
        if ($this->status !== 204 && $this->status !== 304) {
            echo $this->content;
        }
    }

    private function normalizeHeaderName(string $name): string
    {
        return implode('-', array_map(
            static fn (string $part) => ucfirst(strtolower($part)),
            explode('-', trim($name)),
        ));
    }
}
