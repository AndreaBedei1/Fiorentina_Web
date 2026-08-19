<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Accesso alle impostazioni modificabili dal pannello.
 *
 * L'intera tabella viene letta con una sola query e tenuta in memoria per la
 * durata della richiesta: le impostazioni servono in quasi ogni template
 * (footer, contatti, social) e interrogare il database ogni volta sarebbe
 * sprecato.
 */
final class SettingsRepository extends BaseRepository
{
    protected string $table = 'site_settings';

    /** @var array<string, array{value: string|null, type: string}>|null */
    private ?array $cache = null;

    /** @return array<string, array{value: string|null, type: string}> */
    public function allRaw(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $cache = [];

        foreach ($this->db->select('SELECT key_name, value, type FROM site_settings') as $row) {
            $cache[(string) $row['key_name']] = [
                'value' => $row['value'] === null ? null : (string) $row['value'],
                'type' => (string) $row['type'],
            ];
        }

        return $this->cache = $cache;
    }

    /** @return list<array<string, mixed>> Impostazioni complete, per il form del pannello. */
    public function allForAdmin(): array
    {
        return $this->db->select(
            'SELECT * FROM site_settings ORDER BY group_name ASC, sort_order ASC, label ASC'
        );
    }

    /** @return array<string, list<array<string, mixed>>> Impostazioni raggruppate per sezione. */
    public function groupedForAdmin(): array
    {
        $grouped = [];

        foreach ($this->allForAdmin() as $row) {
            $grouped[(string) $row['group_name']][] = $row;
        }

        return $grouped;
    }

    public function put(string $key, ?string $value, ?int $updatedBy = null): void
    {
        $this->db->statement(
            'UPDATE site_settings SET value = :value, updated_by = :user, updated_at = :now
             WHERE key_name = :key',
            ['value' => $value, 'user' => $updatedBy, 'now' => $this->now(), 'key' => $key],
        );

        $this->cache = null;
    }

    /** @param array<string, string|null> $values */
    public function putMany(array $values, ?int $updatedBy = null): void
    {
        $this->db->transaction(function () use ($values, $updatedBy): void {
            foreach ($values as $key => $value) {
                $this->put($key, $value, $updatedBy);
            }
        });

        $this->cache = null;
    }

    /**
     * Crea l'impostazione se assente, senza toccare il valore gia presente.
     * Serve ai seed e agli aggiornamenti: introdurre una nuova chiave non deve
     * sovrascrivere cio che gli amministratori hanno configurato.
     *
     * @param array{group: string, type: string, label: string, description?: string, sort?: int} $meta
     */
    public function ensure(string $key, ?string $defaultValue, array $meta): void
    {
        $exists = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM site_settings WHERE key_name = :key',
            ['key' => $key],
        );

        if ($exists > 0) {
            return;
        }

        $now = $this->now();

        $this->db->insertInto('site_settings', [
            'group_name' => $meta['group'],
            'key_name' => $key,
            'value' => $defaultValue,
            'type' => $meta['type'],
            'label' => $meta['label'],
            'description' => $meta['description'] ?? null,
            'sort_order' => $meta['sort'] ?? 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->cache = null;
    }

    public function forget(): void
    {
        $this->cache = null;
    }
}
