<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Repository di configurazione con accesso "a punti" (`config('mail.from.address')`).
 *
 * I file in config/ restituiscono array e leggono le variabili di ambiente: il
 * codice applicativo non tocca mai `$_ENV` direttamente, così ogni valore ha un
 * default esplicito e un unico punto di verita.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $items = [];

    /** @var array<string, mixed> Cache piatta delle chiavi già risolte. */
    private array $cache = [];

    /** @param array<string, mixed> $items */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /** Carica tutti i file `*.php` di una directory, usando il nome file come namespace. */
    public static function loadFromDirectory(string $directory): self
    {
        $items = [];

        foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $value = require $file;

            if (is_array($value)) {
                $items[$key] = $value;
            }
        }

        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $this->cache[$key] = $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &$this->items;

        foreach ($segments as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
        $this->cache = [];
    }

    public function has(string $key): bool
    {
        return $this->get($key, $sentinel = new \stdClass()) !== $sentinel;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /** @return array<array-key, mixed> */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
