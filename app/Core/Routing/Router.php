<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Container;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Exceptions\HttpException;
use Closure;

/**
 * Router e dispatcher.
 *
 * Le rotte sono dichiarate nei file di routes/ e risolte con un semplice ciclo
 * lineare: con l'ordine di grandezza di questo sito (poche decine di rotte) il
 * costo e trascurabile e il codice resta leggibile. Prima del ciclo proviamo
 * comunque una lookup diretta per le rotte statiche, che sono la maggioranza.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> Indice "METODO path" per le rotte senza parametri. */
    private array $staticRoutes = [];

    /** @var array<string, Route> */
    private array $namedRoutes = [];

    /** @var array{prefix: string, middleware: list<string>, namePrefix: string} */
    private array $groupStack = ['prefix' => '', 'middleware' => [], 'namePrefix' => ''];

    /** @var array<string, class-string> Alias middleware -> classe. */
    private array $middlewareAliases = [];

    public function __construct(private readonly Container $container)
    {
    }

    // -----------------------------------------------------------------------
    //  Registrazione
    // -----------------------------------------------------------------------

    /** @param array{0: class-string, 1: string} $handler */
    public function get(string $uri, array $handler): Route
    {
        return $this->addRoute(['GET', 'HEAD'], $uri, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function post(string $uri, array $handler): Route
    {
        return $this->addRoute(['POST'], $uri, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function put(string $uri, array $handler): Route
    {
        return $this->addRoute(['PUT'], $uri, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function patch(string $uri, array $handler): Route
    {
        return $this->addRoute(['PATCH'], $uri, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function delete(string $uri, array $handler): Route
    {
        return $this->addRoute(['DELETE'], $uri, $handler);
    }

    /**
     * Raggruppa rotte condividendo prefisso URI, middleware e prefisso di nome.
     *
     * @param array{prefix?: string, middleware?: string|list<string>, name?: string} $attributes
     */
    public function group(array $attributes, Closure $callback): void
    {
        $previous = $this->groupStack;

        $this->groupStack = [
            'prefix' => $previous['prefix'] . ($attributes['prefix'] ?? ''),
            'middleware' => array_merge($previous['middleware'], (array) ($attributes['middleware'] ?? [])),
            'namePrefix' => $previous['namePrefix'] . ($attributes['name'] ?? ''),
        ];

        $callback($this);

        $this->groupStack = $previous;
    }

    /** @param array<string, class-string> $aliases */
    public function registerMiddlewareAliases(array $aliases): void
    {
        $this->middlewareAliases = array_merge($this->middlewareAliases, $aliases);
    }

    /**
     * @param list<string>                     $methods
     * @param array{0: class-string, 1: string} $handler
     */
    private function addRoute(array $methods, string $uri, array $handler): Route
    {
        $uri = $this->groupStack['prefix'] . $uri;
        $uri = '/' . trim($uri, '/');
        $uri = $uri === '/' ? '/' : rtrim($uri, '/');

        $route = new Route($methods, $uri, $handler, $this->groupStack['namePrefix']);
        $route->middleware($this->groupStack['middleware']);

        $this->routes[] = $route;

        if ($route->getParameterNames() === []) {
            foreach ($methods as $method) {
                $this->staticRoutes[$method . ' ' . $uri] = $route;
            }
        }

        return $route;
    }

    // -----------------------------------------------------------------------
    //  Dispatch
    // -----------------------------------------------------------------------

    /**
     * @param list<class-string> $globalMiddleware
     */
    public function dispatch(Request $request, array $globalMiddleware = []): Response
    {
        $path = $request->path();
        $method = $request->method();

        $route = $this->findRoute($method, $path);

        if ($route === null) {
            // Distinguiamo 404 da 405: aiuta la diagnosi e i client HTTP corretti.
            $allowed = $this->allowedMethodsFor($path);

            if ($allowed !== []) {
                throw HttpException::methodNotAllowed($allowed);
            }

            throw HttpException::notFound();
        }

        $request->setRouteParameters($route->match($path) ?? [], $route->getName());

        $pipeline = array_merge($globalMiddleware, $this->resolveMiddleware($route->getMiddleware()));

        return $this->runPipeline($request, $pipeline, function (Request $request) use ($route): Response {
            [$class, $method] = $route->getHandler();

            $controller = $this->container->make($class);

            if (! method_exists($controller, $method)) {
                throw new \LogicException(sprintf('Azione %s::%s() inesistente.', $class, $method));
            }

            $response = $controller->{$method}($request);

            if (! $response instanceof Response) {
                throw new \LogicException(sprintf(
                    '%s::%s() deve restituire una Response, ricevuto %s.',
                    $class,
                    $method,
                    get_debug_type($response),
                ));
            }

            return $response;
        });
    }

    private function findRoute(string $method, string $path): ?Route
    {
        if (isset($this->staticRoutes[$method . ' ' . $path])) {
            return $this->staticRoutes[$method . ' ' . $path];
        }

        foreach ($this->routes as $route) {
            if ($route->matchesMethod($method) && $route->match($path) !== null) {
                return $route;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function allowedMethodsFor(string $path): array
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if ($route->match($path) !== null) {
                foreach ($route->getMethods() as $method) {
                    $allowed[$method] = true;
                }
            }
        }

        return array_keys($allowed);
    }

    /**
     * @param list<string> $names
     * @return list<class-string>
     */
    private function resolveMiddleware(array $names): array
    {
        $resolved = [];

        foreach ($names as $name) {
            $class = $this->middlewareAliases[$name] ?? $name;

            if (! class_exists($class)) {
                throw new \LogicException(sprintf('Middleware "%s" non registrato.', $name));
            }

            $resolved[] = $class;
        }

        return $resolved;
    }

    /**
     * Esegue la catena di middleware "a cipolla": ogni middleware puo agire
     * prima e dopo il resto della pipeline, e puo interromperla restituendo
     * direttamente una Response.
     *
     * @param list<class-string> $middleware
     */
    private function runPipeline(Request $request, array $middleware, Closure $destination): Response
    {
        $next = $destination;

        foreach (array_reverse($middleware) as $class) {
            $previous = $next;
            $next = function (Request $request) use ($class, $previous): Response {
                /** @var \App\Middleware\MiddlewareInterface $instance */
                $instance = $this->container->make($class);

                return $instance->handle($request, $previous);
            };
        }

        return $next($request);
    }

    // -----------------------------------------------------------------------
    //  Introspezione
    // -----------------------------------------------------------------------

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    public function routeByName(string $name): ?Route
    {
        if ($this->namedRoutes === []) {
            foreach ($this->routes as $route) {
                if ($route->getName() !== null) {
                    $this->namedRoutes[$route->getName()] = $route;
                }
            }
        }

        return $this->namedRoutes[$name] ?? null;
    }
}
