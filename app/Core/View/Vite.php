<?php

declare(strict_types=1);

namespace App\Core\View;

/**
 * Ponte fra gli asset compilati da Vite e i template Twig.
 *
 * In produzione legge il manifest generato da `npm run build` ed emette i tag
 * con i nomi file "hashati" (cache busting immediato a ogni deploy). In
 * sviluppo, se e attivo `npm run dev`, punta al dev server per avere l'HMR.
 *
 * Il server di produzione non esegue mai Node: legge solo un JSON.
 */
final class Vite
{
    /** @var array<string, array{file: string, css?: list<string>, imports?: list<string>}>|null */
    private ?array $manifest = null;

    private ?string $devServerUrl = null;

    private bool $devServerResolved = false;

    public function __construct(
        private readonly string $manifestPath,
        private readonly string $hotFilePath,
        private readonly string $buildDirectory = '/assets',
    ) {
    }

    /** Indica se il dev server Vite e in esecuzione. */
    public function isRunningHot(): bool
    {
        return $this->devServerUrl() !== null;
    }

    /**
     * Tag HTML (CSS + JS) per uno o più entrypoint.
     *
     * @param string|list<string> $entrypoints Percorsi come dichiarati in vite.config.js.
     */
    public function tags(string|array $entrypoints): string
    {
        $entrypoints = (array) $entrypoints;

        if ($this->isRunningHot()) {
            $html = sprintf('<script type="module" src="%s/@vite/client"></script>', $this->devServerUrl());

            foreach ($entrypoints as $entry) {
                $html .= sprintf('<script type="module" src="%s/%s"></script>', $this->devServerUrl(), ltrim($entry, '/'));
            }

            return $html;
        }

        $manifest = $this->manifest();

        if ($manifest === []) {
            // Build assente: meglio un avviso visibile in pagina che una pagina
            // senza stile e senza spiegazione.
            return '<!-- Asset non compilati: eseguire `npm run build`. -->';
        }

        $styles = '';
        $scripts = '';
        $preloads = '';

        foreach ($entrypoints as $entry) {
            $chunk = $manifest[ltrim($entry, '/')] ?? null;

            if ($chunk === null) {
                continue;
            }

            foreach ($this->collectCss($chunk, $manifest) as $cssFile) {
                $styles .= sprintf('<link rel="stylesheet" href="%s">', $this->asset($cssFile));
            }

            foreach ($chunk['imports'] ?? [] as $import) {
                if (isset($manifest[$import]['file'])) {
                    $preloads .= sprintf('<link rel="modulepreload" href="%s">', $this->asset($manifest[$import]['file']));
                }
            }

            $scripts .= sprintf('<script type="module" src="%s"></script>', $this->asset($chunk['file']));
        }

        // Il CSS precede il JS: evita il flash di contenuto non stilizzato.
        return $styles . $preloads . $scripts;
    }

    /** URL pubblico di un file già presente nel manifest. */
    public function asset(string $file): string
    {
        return rtrim($this->buildDirectory, '/') . '/' . ltrim($file, '/');
    }

    /**
     * Raccoglie il CSS di un chunk e dei suoi import statici.
     *
     * @param array{file: string, css?: list<string>, imports?: list<string>} $chunk
     * @param array<string, array<string, mixed>>                             $manifest
     * @return list<string>
     */
    private function collectCss(array $chunk, array $manifest, array &$seen = []): array
    {
        $files = [];

        foreach ($chunk['css'] ?? [] as $css) {
            if (! isset($seen[$css])) {
                $seen[$css] = true;
                $files[] = $css;
            }
        }

        foreach ($chunk['imports'] ?? [] as $import) {
            if (isset($manifest[$import]) && ! isset($seen['@' . $import])) {
                $seen['@' . $import] = true;
                /** @var array{file: string, css?: list<string>, imports?: list<string>} $imported */
                $imported = $manifest[$import];
                $files = array_merge($files, $this->collectCss($imported, $manifest, $seen));
            }
        }

        return $files;
    }

    /** @return array<string, array{file: string, css?: list<string>, imports?: list<string>}> */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        if (! is_file($this->manifestPath)) {
            return $this->manifest = [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($this->manifestPath), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->manifest = [];
        }

        return $this->manifest = is_array($decoded) ? $decoded : [];
    }

    private function devServerUrl(): ?string
    {
        if ($this->devServerResolved) {
            return $this->devServerUrl;
        }

        $this->devServerResolved = true;

        if (is_file($this->hotFilePath)) {
            $url = trim((string) file_get_contents($this->hotFilePath));

            // Accettiamo solo un indirizzo locale: il file hot non deve poter
            // dirottare gli script della pagina verso un host arbitrario.
            if (preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $url) === 1) {
                $this->devServerUrl = $url;
            }
        }

        return $this->devServerUrl;
    }
}
