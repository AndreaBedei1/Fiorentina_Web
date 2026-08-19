<?php

declare(strict_types=1);

/**
 * Funzioni globali del progetto.
 *
 * Sono volutamente poche: solo cio che serve davvero ovunque (lettura
 * ambiente, percorsi, accesso al container). Tutto il resto vive in classi.
 */

use App\Core\Application;
use App\Core\Config;

if (! function_exists('env')) {
    /**
     * Legge una variabile di ambiente con conversione dei valori tipici.
     *
     * Usata soltanto dai file in config/: il resto del codice legge la
     * configurazione, cosi ogni valore ha un default esplicito in un solo punto.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        // Le stringhe fra virgolette nel .env conservano gli spazi interni.
        if (strlen($trimmed) >= 2
            && ($trimmed[0] === '"' && $trimmed[-1] === '"' || $trimmed[0] === "'" && $trimmed[-1] === "'")
        ) {
            return substr($trimmed, 1, -1);
        }

        return match (strtolower($trimmed)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $trimmed,
        };
    }
}

if (! function_exists('app')) {
    /**
     * Istanza dell'applicazione, o servizio risolto dal container.
     *
     * @template T of object
     * @param class-string<T>|null $abstract
     * @return ($abstract is null ? Application : T)
     */
    function app(?string $abstract = null): mixed
    {
        $app = Application::getInstance();

        return $abstract === null ? $app : $app->get($abstract);
    }
}

if (! function_exists('config')) {
    /** Valore di configurazione con notazione a punti. */
    function config(string $key, mixed $default = null): mixed
    {
        return app(Config::class)->get($key, $default);
    }
}

if (! function_exists('base_path')) {
    function base_path(string $append = ''): string
    {
        return app()->basePath($append);
    }
}

if (! function_exists('storage_path')) {
    function storage_path(string $append = ''): string
    {
        return app()->storagePath($append);
    }
}

if (! function_exists('public_path')) {
    function public_path(string $append = ''): string
    {
        return app()->publicPath($append);
    }
}

if (! function_exists('resource_path')) {
    function resource_path(string $append = ''): string
    {
        return app()->resourcePath($append);
    }
}

if (! function_exists('database_path')) {
    function database_path(string $append = ''): string
    {
        return app()->databasePath($append);
    }
}

if (! function_exists('e')) {
    /** Escape HTML. Nei template ci pensa Twig: serve nel codice PHP che genera markup. */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('ensure_directory')) {
    /** Crea una directory se non esiste. Restituisce false invece di lanciare eccezioni. */
    function ensure_directory(string $path, int $permissions = 0o755): bool
    {
        if (is_dir($path)) {
            return true;
        }

        return mkdir($path, $permissions, true) || is_dir($path);
    }
}
