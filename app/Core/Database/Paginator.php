<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Risultato paginato.
 *
 * La paginazione e obbligatoria su galleria, notizie, ordini e audit log:
 * senza, un archivio di migliaia di fotografie manderebbe in sofferenza sia il
 * database sia il browser dell'amministratore.
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Paginator implements \IteratorAggregate, \Countable
{
    /**
     * @param list<T>              $items
     * @param array<string, mixed> $queryParameters Filtri da conservare nei link di pagina.
     */
    public function __construct(
        private readonly array $items,
        private readonly int $total,
        private readonly int $perPage,
        private readonly int $currentPage,
        private readonly string $basePath = '',
        private readonly array $queryParameters = [],
    ) {
    }

    /** @return self<mixed> */
    public static function empty(int $perPage = 12): self
    {
        return new self([], 0, $perPage, 1);
    }

    /** @return list<T> */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function hasPages(): bool
    {
        return $this->lastPage() > 1;
    }

    public function hasPrevious(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNext(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    public function previousPage(): ?int
    {
        return $this->hasPrevious() ? $this->currentPage - 1 : null;
    }

    public function nextPage(): ?int
    {
        return $this->hasNext() ? $this->currentPage + 1 : null;
    }

    public function firstItemNumber(): int
    {
        return $this->total === 0 ? 0 : ($this->currentPage - 1) * $this->perPage + 1;
    }

    public function lastItemNumber(): int
    {
        return min($this->total, $this->currentPage * $this->perPage);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    /** URL di una pagina, preservando i filtri attivi. */
    public function url(int $page): string
    {
        $parameters = $this->queryParameters;
        $parameters['pagina'] = $page;

        return $this->basePath . '?' . http_build_query($parameters);
    }

    /**
     * Finestra di pagine da mostrare, con `null` al posto dei salti.
     * Mostrarne troppe rende la paginazione inutilizzabile da smartphone.
     *
     * @return list<int|null>
     */
    public function window(int $each = 2): array
    {
        $last = $this->lastPage();

        if ($last <= 7) {
            return range(1, $last);
        }

        $pages = [1];
        $start = max(2, $this->currentPage - $each);
        $end = min($last - 1, $this->currentPage + $each);

        if ($start > 2) {
            $pages[] = null;
        }

        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }

        if ($end < $last - 1) {
            $pages[] = null;
        }

        $pages[] = $last;

        return $pages;
    }

    /**
     * Sostituisce gli elementi conservando i metadati di pagina.
     *
     * Serve quando le righe vengono idratate in un secondo momento (per
     * esempio caricando immagini e varianti in blocco per evitare le query N+1).
     *
     * @template U
     * @param list<U> $items
     * @return self<U>
     */
    public function withItems(array $items): self
    {
        return new self(
            $items,
            $this->total,
            $this->perPage,
            $this->currentPage,
            $this->basePath,
            $this->queryParameters,
        );
    }

    /**
     * Applica una trasformazione agli elementi mantenendo i metadati di pagina.
     *
     * @template U
     * @param callable(T): U $callback
     * @return self<U>
     */
    public function map(callable $callback): self
    {
        return new self(
            array_map($callback, $this->items),
            $this->total,
            $this->perPage,
            $this->currentPage,
            $this->basePath,
            $this->queryParameters,
        );
    }

    /** @return \ArrayIterator<int, T> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
