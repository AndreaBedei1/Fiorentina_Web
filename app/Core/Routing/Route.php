<?php

declare(strict_types=1);

namespace App\Core\Routing;

/**
 * Definizione di una singola rotta.
 *
 * Il pattern usa la sintassi `{parametro}` con vincolo opzionale
 * (`{id:\d+}`) e suffisso `?` per i segmenti facoltativi (`{pagina?}`).
 */
final class Route
{
    /** @var list<string> */
    private array $middleware = [];

    private ?string $name = null;

    private string $compiled;

    /** @var list<string> */
    private array $parameterNames = [];

    /**
     * @param list<string>                     $methods
     * @param array{0: class-string, 1: string} $handler
     */
    public function __construct(
        private readonly array $methods,
        private readonly string $uri,
        private readonly array $handler,
        private readonly string $namePrefix = '',
    ) {
        $this->compiled = $this->compile($uri);
    }

    /** Il nome finale include il prefisso ereditato dal gruppo. */
    public function name(string $name): self
    {
        $this->name = $this->namePrefix . $name;

        return $this;
    }

    /** @param string|list<string> $middleware */
    public function middleware(string|array $middleware): self
    {
        foreach ((array) $middleware as $item) {
            if (! in_array($item, $this->middleware, true)) {
                $this->middleware[] = $item;
            }
        }

        return $this;
    }

    /** @return list<string> */
    public function getMethods(): array
    {
        return $this->methods;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    /** @return array{0: class-string, 1: string} */
    public function getHandler(): array
    {
        return $this->handler;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /** @return list<string> */
    public function getParameterNames(): array
    {
        return $this->parameterNames;
    }

    public function matchesMethod(string $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    /**
     * Confronta il path con il pattern compilato.
     *
     * @return array<string, string>|null Parametri estratti, oppure null se non combacia.
     */
    public function match(string $path): ?array
    {
        if (preg_match($this->compiled, $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($this->parameterNames as $parameter) {
            if (isset($matches[$parameter]) && $matches[$parameter] !== '') {
                $parameters[$parameter] = $matches[$parameter];
            }
        }

        return $parameters;
    }

    /**
     * Ricostruisce l'URL sostituendo i parametri.
     *
     * @param array<string, string|int> $parameters
     */
    public function buildUri(array $parameters = []): string
    {
        $uri = preg_replace_callback(
            '#/\{(\w+)(?::[^}]+)?(\?)?\}#',
            static function (array $m) use (&$parameters): string {
                $key = $m[1];
                $optional = ($m[2] ?? '') === '?';

                if (! array_key_exists($key, $parameters)) {
                    if ($optional) {
                        return '';
                    }

                    throw new \InvalidArgumentException(sprintf('Parametro di rotta "%s" mancante.', $key));
                }

                $value = (string) $parameters[$key];
                unset($parameters[$key]);

                return '/' . rawurlencode($value);
            },
            $this->uri,
        ) ?? $this->uri;

        // I parametri residui diventano query string.
        if ($parameters !== []) {
            $uri .= '?' . http_build_query($parameters);
        }

        return $uri === '' ? '/' : $uri;
    }

    /** Traduce il pattern leggibile in una regex con gruppi nominati. */
    private function compile(string $uri): string
    {
        $this->parameterNames = [];

        $pattern = preg_replace_callback(
            '#/\{(\w+)(?::([^}]+))?(\?)?\}#',
            function (array $m): string {
                $name = $m[1];
                $constraint = $m[2] ?? '';
                $optional = ($m[3] ?? '') === '?';

                $this->parameterNames[] = $name;

                $expression = $constraint !== '' ? $constraint : '[^/]+';
                $group = sprintf('/(?P<%s>%s)', $name, $expression);

                return $optional ? '(?:' . $group . ')?' : $group;
            },
            $uri,
        ) ?? $uri;

        return '#^' . $pattern . '$#u';
    }
}
