<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Support\Str;

/**
 * Metadati SEO di una pagina.
 *
 * Ogni controller costruisce un SeoMeta e lo passa alla vista: il layout ha un
 * unico punto in cui stampare title, description, canonical, OpenGraph e dati
 * strutturati. Senza questa disciplina, i metadati finiscono duplicati e
 * incoerenti fra le pagine.
 */
final class SeoMeta
{
    /** @var list<array{name: string, url: string}> */
    private array $breadcrumbs = [];

    /** @var array<string, mixed>|null */
    private ?array $structuredData = null;

    private ?string $imageUrl = null;

    private string $type = 'website';

    private bool $noindex = false;

    private ?string $canonical = null;

    public function __construct(
        private string $title,
        private string $description = '',
    ) {
    }

    public static function make(string $title, string $description = ''): self
    {
        return new self($title, $description);
    }

    /** Titolo completo: "Pagina - Nome del gruppo". */
    public function fullTitle(string $siteName): string
    {
        if ($this->title === '' || $this->title === $siteName) {
            return $siteName;
        }

        return Str::truncate($this->title, 65, '') . ' - ' . $siteName;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        // Google tronca oltre i 160 caratteri circa: meglio decidere noi dove.
        return Str::truncate($this->description, 158);
    }

    public function withDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function withImage(?string $url): self
    {
        $this->imageUrl = $url;

        return $this;
    }

    public function imageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function withType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function withCanonical(string $url): self
    {
        $this->canonical = $url;

        return $this;
    }

    public function canonical(): ?string
    {
        return $this->canonical;
    }

    /** Esclude la pagina dall indicizzazione (carrello, ordine, area riservata). */
    public function withNoindex(bool $noindex = true): self
    {
        $this->noindex = $noindex;

        return $this;
    }

    public function isNoindex(): bool
    {
        return $this->noindex;
    }

    /** @param list<array{name: string, url: string}> $breadcrumbs */
    public function withBreadcrumbs(array $breadcrumbs): self
    {
        $this->breadcrumbs = $breadcrumbs;

        return $this;
    }

    /** @return list<array{name: string, url: string}> */
    public function breadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    public function hasBreadcrumbs(): bool
    {
        return $this->breadcrumbs !== [];
    }

    /** @param array<string, mixed> $data */
    public function withStructuredData(array $data): self
    {
        $this->structuredData = $data;

        return $this;
    }

    /** Dati strutturati Schema.org già serializzati per il tag script. */
    public function structuredDataJson(): ?string
    {
        if ($this->structuredData === null) {
            return null;
        }

        return json_encode(
            $this->structuredData,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ) ?: null;
    }

    /** Breadcrumb in formato Schema.org, generato dai breadcrumb già impostati. */
    public function breadcrumbJson(string $baseUrl): ?string
    {
        if ($this->breadcrumbs === []) {
            return null;
        }

        $items = [];
        $position = 1;

        foreach ($this->breadcrumbs as $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $crumb['name'],
                'item' => str_starts_with($crumb['url'], 'http')
                    ? $crumb['url']
                    : rtrim($baseUrl, '/') . $crumb['url'],
            ];
        }

        return json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }
}
