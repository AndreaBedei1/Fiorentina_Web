<?php

declare(strict_types=1);

namespace App\Services\Social;

/** Riepilogo di una sincronizzazione dei contenuti social. */
final class SocialSyncReport
{
    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<string> */
    private array $errors = [];

    private int $thumbnailsDownloaded = 0;

    public function record(string $provider, int $count): void
    {
        $this->counts[$provider] = ($this->counts[$provider] ?? 0) + $count;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function countThumbnail(): void
    {
        $this->thumbnailsDownloaded++;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    public function thumbnails(): int
    {
        return $this->thumbnailsDownloaded;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function summary(): string
    {
        if ($this->counts === []) {
            return 'Nessun contenuto social sincronizzato.';
        }

        $parts = [];

        foreach ($this->counts as $provider => $count) {
            $parts[] = sprintf('%s: %d', $provider, $count);
        }

        return sprintf(
            '%s (%d miniature scaricate)%s.',
            implode(', ', $parts),
            $this->thumbnailsDownloaded,
            $this->hasErrors() ? sprintf(', %d con avvisi', count($this->errors)) : '',
        );
    }
}
