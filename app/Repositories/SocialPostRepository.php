<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\SocialPost;

/** Cache locale dei contenuti social. */
final class SocialPostRepository extends BaseRepository
{
    protected string $table = 'social_posts';

    /**
     * Ultimi contenuti visibili, eventualmente filtrati per provider.
     *
     * @param list<string> $providers
     * @return list<SocialPost>
     */
    public function latest(int $limit = 6, array $providers = []): array
    {
        $sql = 'SELECT * FROM social_posts WHERE is_visible = 1';
        $bindings = [];

        if ($providers !== []) {
            $placeholders = [];

            foreach (array_values($providers) as $index => $provider) {
                $key = 'p' . $index;
                $placeholders[] = ':' . $key;
                $bindings[$key] = $provider;
            }

            $sql .= ' AND provider IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY published_at DESC, id DESC LIMIT ' . max(1, min(30, $limit));

        return array_map(SocialPost::fromRow(...), $this->db->select($sql, $bindings));
    }

    /** @return list<SocialPost> */
    public function allForAdmin(int $limit = 60): array
    {
        return array_map(
            SocialPost::fromRow(...),
            $this->db->select(
                'SELECT * FROM social_posts ORDER BY published_at DESC, id DESC LIMIT ' . max(1, min(200, $limit))
            ),
        );
    }

    public function find(int $id): ?SocialPost
    {
        $row = $this->db->selectOne('SELECT * FROM social_posts WHERE id = :id', ['id' => $id]);

        return $row === null ? null : SocialPost::fromRow($row);
    }

    /**
     * Inserisce o aggiorna un contenuto social.
     *
     * Non cancelliamo mai i post esistenti durante la sincronizzazione: se
     * l'API risponde con un errore o con una lista vuota, il sito continua a
     * mostrare l'ultimo stato buono invece di una sezione vuota.
     *
     * @param array<string, mixed> $data
     */
    public function upsert(array $data): void
    {
        $now = $this->now();

        $existingId = $this->db->scalar(
            'SELECT id FROM social_posts WHERE provider = :provider AND external_id = :external',
            ['provider' => $data['provider'], 'external' => $data['external_id']],
        );

        if ($existingId !== null) {
            $update = $data;
            unset($update['provider'], $update['external_id']);

            // La miniatura locale si aggiorna solo se ne e stata scaricata una nuova.
            if (($update['local_thumb_key'] ?? null) === null) {
                unset($update['local_thumb_key']);
            }

            $update['synced_at'] = $now;
            $update['updated_at'] = $now;

            $this->db->updateWhereId('social_posts', (int) $existingId, $update);

            return;
        }

        $data['synced_at'] = $now;
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $this->db->insertInto('social_posts', $data);
    }

    public function setVisibility(int $id, bool $visible): bool
    {
        return $this->db->updateWhereId('social_posts', $id, [
            'is_visible' => $visible ? 1 : 0,
            'updated_at' => $this->now(),
        ]) > 0;
    }

    public function delete(int $id): ?string
    {
        $row = $this->db->selectOne('SELECT local_thumb_key FROM social_posts WHERE id = :id', ['id' => $id]);

        $this->db->statement('DELETE FROM social_posts WHERE id = :id', ['id' => $id]);

        return $row === null || $row['local_thumb_key'] === null ? null : (string) $row['local_thumb_key'];
    }

    public function lastSyncedAt(): ?string
    {
        $value = $this->db->scalar('SELECT MAX(synced_at) FROM social_posts');

        return $value === null ? null : (string) $value;
    }

    public function count(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM social_posts');
    }

    /**
     * Mantiene solo i contenuti più recenti per ciascun provider.
     *
     * @return list<string> Chiavi delle miniature locali da eliminare da disco.
     */
    public function trimToLatest(string $provider, int $keep = 24): array
    {
        $ids = $this->db->column(
            'SELECT id FROM social_posts WHERE provider = :provider
             ORDER BY published_at DESC, id DESC LIMIT 500 OFFSET ' . max(1, $keep),
            ['provider' => $provider],
        );

        $removedKeys = [];

        foreach ($ids as $id) {
            $key = $this->delete((int) $id);

            if ($key !== null) {
                $removedKeys[] = $key;
            }
        }

        return $removedKeys;
    }
}
