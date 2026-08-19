<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;
use App\Core\Database\Paginator;

/**
 * Base dei repository: incapsula tutto l'accesso SQL.
 *
 * Nessun controller e nessun template scrive query. I service orchestrano, i
 * repository parlano con il database: e il confine che rende testabile la
 * logica e prevedibile il costo delle pagine.
 */
abstract class BaseRepository
{
    /** Nome della tabella gestita dal repository. */
    protected string $table = '';

    public function __construct(protected readonly Connection $db)
    {
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    /**
     * Esegue una query paginata.
     *
     * LIMIT e OFFSET vengono interpolati come interi gia validati: alcune
     * combinazioni di MySQL e PDO rifiutano di trattarli come parametri
     * associati, e forzare il cast a int rende comunque impossibile l'iniezione.
     *
     * @template T
     * @param array<string, mixed>          $bindings
     * @param callable(array<string,mixed>): T $mapper
     * @param array<string, mixed>          $queryParameters
     * @return Paginator<T>
     */
    protected function paginateQuery(
        string $sql,
        string $countSql,
        array $bindings,
        int $page,
        int $perPage,
        callable $mapper,
        string $basePath = '',
        array $queryParameters = [],
    ): Paginator {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $total = (int) $this->db->scalar($countSql, $bindings);

        if ($total === 0) {
            return new Paginator([], 0, $perPage, $page, $basePath, $queryParameters);
        }

        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        $rows = $this->db->select(
            $sql . sprintf(' LIMIT %d OFFSET %d', $perPage, $offset),
            $bindings,
        );

        return new Paginator(
            array_map($mapper, $rows),
            $total,
            $perPage,
            $page,
            $basePath,
            $queryParameters,
        );
    }

    /** Marca temporale corrente nel formato accettato da MySQL. */
    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Genera uno slug unico sulla tabella, aggiungendo un suffisso numerico in
     * caso di collisione: "cena-sociale-2026", poi "cena-sociale-2026-2".
     */
    protected function uniqueSlug(
        string $baseSlug,
        ?int $ignoreId = null,
        string $column = 'slug',
        ?string $table = null,
    ): string {
        $column = $this->db->quoteIdentifier($column);
        $table = $this->db->quoteIdentifier($table ?? $this->table);

        $sql = sprintf('SELECT COUNT(*) FROM %s WHERE %s = :slug', $table, $column);
        $bindings = ['slug' => $baseSlug];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ignore_id';
            $bindings['ignore_id'] = $ignoreId;
        }

        if ((int) $this->db->scalar($sql, $bindings) === 0) {
            return $baseSlug;
        }

        // Un limite esplicito evita un ciclo infinito se qualcosa va storto.
        for ($suffix = 2; $suffix < 500; $suffix++) {
            $candidate = $baseSlug . '-' . $suffix;
            $bindings['slug'] = $candidate;

            if ((int) $this->db->scalar($sql, $bindings) === 0) {
                return $candidate;
            }
        }

        return $baseSlug . '-' . bin2hex(random_bytes(3));
    }

    /**
     * Costruisce la clausola ORDER BY solo da colonne esplicitamente consentite.
     * Impedisce che un parametro di query arrivi dentro l'SQL.
     *
     * @param array<string, string> $allowed Chiave pubblica => espressione SQL.
     */
    protected function safeOrderBy(?string $requested, array $allowed, string $default): string
    {
        if ($requested !== null && isset($allowed[$requested])) {
            return $allowed[$requested];
        }

        return $allowed[$default] ?? reset($allowed);
    }

    /** Segna una riga come eliminata senza rimuoverla (soft delete). */
    protected function softDelete(int $id): bool
    {
        return $this->db->updateWhereId($this->table, $id, [
            'deleted_at' => $this->now(),
            'updated_at' => $this->now(),
        ]) > 0;
    }

    protected function restoreSoftDeleted(int $id): bool
    {
        return $this->db->updateWhereId($this->table, $id, [
            'deleted_at' => null,
            'updated_at' => $this->now(),
        ]) > 0;
    }

    public function countAll(string $where = '', array $bindings = []): int
    {
        $sql = sprintf('SELECT COUNT(*) FROM %s', $this->db->quoteIdentifier($this->table));

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return (int) $this->db->scalar($sql, $bindings);
    }
}
