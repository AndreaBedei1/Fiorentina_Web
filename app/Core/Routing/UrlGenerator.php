<?php

declare(strict_types=1);

namespace App\Core\Routing;

/**
 * Costruisce gli URL a partire dai nomi di rotta.
 *
 * Nessun template scrive URL a mano: cambiare un percorso significa toccare un
 * solo file in routes/, e i link restano coerenti in tutto il sito (utile anche
 * per la SEO, che soffre i link rotti).
 */
final class UrlGenerator
{
    public function __construct(
        private readonly Router $router,
        private string $baseUrl = '',
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * URL relativo alla radice del sito.
     *
     * @param array<string, string|int> $parameters
     */
    public function route(string $name, array $parameters = []): string
    {
        $route = $this->router->routeByName($name);

        if ($route === null) {
            throw new \InvalidArgumentException(sprintf('Rotta "%s" non definita.', $name));
        }

        return $route->buildUri($parameters);
    }

    /**
     * URL assoluto: necessario per canonical, OpenGraph, sitemap ed email.
     *
     * @param array<string, string|int> $parameters
     */
    public function absoluteRoute(string $name, array $parameters = []): string
    {
        return $this->baseUrl . $this->route($name, $parameters);
    }

    public function to(string $path): string
    {
        return '/' . ltrim($path, '/');
    }

    public function absolute(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    public function has(string $name): bool
    {
        return $this->router->routeByName($name) !== null;
    }
}
