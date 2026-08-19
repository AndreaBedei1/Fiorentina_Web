<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ContainerException;
use Closure;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Container di dipendenze minimale con autowiring.
 *
 * Non implementiamo l'intero PSR-11 con le sue eccezioni tipizzate: il progetto
 * ha una superficie contenuta e un container di ~150 righe resta piu leggibile
 * (e piu facile da manutenere per chi ereditera il codice) di una dipendenza
 * esterna. L'autowiring per type-hint evita centinaia di factory manuali.
 */
class Container
{
    /** @var array<string, array{factory: Closure, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, true> Catena di risoluzione, per intercettare le dipendenze circolari. */
    private array $resolving = [];

    /**
     * Registra una definizione.
     *
     * @param Closure|string|null $concrete Closure factory, nome di classe, oppure
     *                                      null per usare $id stesso come classe.
     */
    public function bind(string $id, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $concrete ??= $id;

        $factory = $concrete instanceof Closure
            ? $concrete
            : fn (Container $c) => $c->build($concrete);

        $this->bindings[$id] = ['factory' => $factory, 'shared' => $shared];

        // Una nuova definizione invalida l'eventuale istanza gia risolta.
        unset($this->instances[$id]);
    }

    public function singleton(string $id, Closure|string|null $concrete = null): void
    {
        $this->bind($id, $concrete, true);
    }

    /** Registra un oggetto gia costruito. */
    public function instance(string $id, mixed $object): void
    {
        $this->instances[$id] = $object;
    }

    public function alias(string $alias, string $id): void
    {
        $this->aliases[$alias] = $id;
    }

    public function has(string $id): bool
    {
        $id = $this->resolveAlias($id);

        return isset($this->bindings[$id]) || isset($this->instances[$id]) || class_exists($id);
    }

    /** @template T of object @param class-string<T>|string $id @return ($id is class-string<T> ? T : mixed) */
    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    /**
     * Risolve un servizio.
     *
     * @param array<string, mixed> $parameters Override per nome di parametro del costruttore.
     */
    public function make(string $id, array $parameters = []): mixed
    {
        $id = $this->resolveAlias($id);

        if ($parameters === [] && array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->resolving[$id])) {
            throw new ContainerException(sprintf(
                'Dipendenza circolare rilevata risolvendo "%s" (catena: %s).',
                $id,
                implode(' -> ', array_keys($this->resolving))
            ));
        }

        $this->resolving[$id] = true;

        try {
            if (isset($this->bindings[$id])) {
                $binding = $this->bindings[$id];
                $object = ($binding['factory'])($this, $parameters);

                if ($binding['shared'] && $parameters === []) {
                    $this->instances[$id] = $object;
                }

                return $object;
            }

            if (! class_exists($id)) {
                throw new ContainerException(sprintf('Servizio "%s" non registrato e non istanziabile.', $id));
            }

            return $this->build($id, $parameters);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    /**
     * Istanzia una classe risolvendo ricorsivamente le dipendenze del costruttore.
     *
     * @param array<string, mixed> $parameters
     */
    public function build(string $class, array $parameters = []): object
    {
        $reflector = new ReflectionClass($class);

        if (! $reflector->isInstantiable()) {
            throw new ContainerException(sprintf('La classe "%s" non e istanziabile.', $class));
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $this->resolveParameter($parameter, $parameters, $class);
        }

        return $reflector->newInstanceArgs($arguments);
    }

    /** @param array<string, mixed> $overrides */
    private function resolveParameter(ReflectionParameter $parameter, array $overrides, string $owner): mixed
    {
        $name = $parameter->getName();

        if (array_key_exists($name, $overrides)) {
            return $overrides[$name];
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $dependency = $type->getName();

            if ($dependency === self::class || is_subclass_of($dependency, self::class)) {
                return $this;
            }

            if ($this->has($dependency)) {
                return $this->make($dependency);
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        throw new ContainerException(sprintf(
            'Impossibile risolvere il parametro "$%s" del costruttore di %s.',
            $name,
            $owner
        ));
    }

    private function resolveAlias(string $id): string
    {
        return $this->aliases[$id] ?? $id;
    }
}
