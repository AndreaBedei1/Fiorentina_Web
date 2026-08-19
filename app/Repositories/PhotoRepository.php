<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Models\Photo;

/** Accesso alle fotografie della galleria. */
final class PhotoRepository extends BaseRepository
{
    protected string $table = 'photos';

    public function find(int $id): ?Photo
    {
        $row = $this->db->selectOne(
            'SELECT p.*, a.title AS album_title, a.slug AS album_slug
             FROM photos p JOIN albums a ON a.id = p.album_id
             WHERE p.id = :id',
            ['id' => $id],
        );

        return $row === null ? null : Photo::fromRow($row);
    }

    /**
     * Fotografie pubblicate di un album.
     *
     * @return list<Photo>
     */
    public function publishedForAlbum(int $albumId): array
    {
        $rows = $this->db->select(
            "SELECT p.*, a.title AS album_title, a.slug AS album_slug
             FROM photos p JOIN albums a ON a.id = p.album_id
             WHERE p.album_id = :album AND p.status = 'published'
             ORDER BY p.sort_order ASC, p.id ASC",
            ['album' => $albumId],
        );

        return array_map(Photo::fromRow(...), $rows);
    }

    /** @return Paginator<Photo> */
    public function paginatePublishedForAlbum(int $albumId, int $page, int $perPage = 36, string $basePath = ''): Paginator
    {
        return $this->paginateQuery(
            "SELECT p.*, a.title AS album_title, a.slug AS album_slug
             FROM photos p JOIN albums a ON a.id = p.album_id
             WHERE p.album_id = :album AND p.status = 'published'
             ORDER BY p.sort_order ASC, p.id ASC",
            "SELECT COUNT(*) FROM photos p WHERE p.album_id = :album AND p.status = 'published'",
            ['album' => $albumId],
            $page,
            $perPage,
            Photo::fromRow(...),
            $basePath,
        );
    }

    /** Tutte le foto di un album, incluse le nascoste: vista amministrativa. @return list<Photo> */
    public function allForAlbum(int $albumId): array
    {
        $rows = $this->db->select(
            'SELECT p.* FROM photos p WHERE p.album_id = :album ORDER BY p.sort_order ASC, p.id ASC',
            ['album' => $albumId],
        );

        return array_map(Photo::fromRow(...), $rows);
    }

    /** Anteprime recenti per la homepage, dai soli album pubblicati. @return list<Photo> */
    public function latestPublished(int $limit = 8): array
    {
        $rows = $this->db->select(
            "SELECT p.*, a.title AS album_title, a.slug AS album_slug
             FROM photos p JOIN albums a ON a.id = p.album_id
             WHERE p.status = 'published' AND a.status = 'published' AND a.deleted_at IS NULL
             ORDER BY COALESCE(a.event_date, a.created_at) DESC, p.sort_order ASC
             LIMIT " . max(1, min(40, $limit)),
        );

        return array_map(Photo::fromRow(...), $rows);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = $this->now();
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->db->insertInto('photos', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('photos', $id, $data) >= 0;
    }

    /**
     * Elimina il record. I file su disco vengono rimossi dal service chiamante,
     * che e l'unico a conoscere la struttura dello storage.
     */
    public function delete(int $id): bool
    {
        return $this->db->statement('DELETE FROM photos WHERE id = :id', ['id' => $id]) > 0;
    }

    /** Prossima posizione libera nell'ordinamento di un album. */
    public function nextSortOrder(int $albumId): int
    {
        return (int) $this->db->scalar(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM photos WHERE album_id = :album',
            ['album' => $albumId],
        );
    }

    /**
     * Riordina le fotografie di un album secondo la sequenza di ID ricevuta.
     *
     * @param list<int> $orderedIds
     */
    public function reorder(int $albumId, array $orderedIds): void
    {
        if ($orderedIds === []) {
            return;
        }

        $this->db->transaction(function () use ($albumId, $orderedIds): void {
            $position = 1;

            foreach ($orderedIds as $photoId) {
                $this->db->statement(
                    'UPDATE photos SET sort_order = :position, updated_at = :now
                     WHERE id = :id AND album_id = :album',
                    [
                        'position' => $position++,
                        'now' => $this->now(),
                        'id' => (int) $photoId,
                        'album' => $albumId,
                    ],
                );
            }
        });
    }

    /** @return list<string> Chiavi di archiviazione delle foto di un album. */
    public function storageKeysForAlbum(int $albumId): array
    {
        return array_map('strval', $this->db->column(
            'SELECT storage_key FROM photos WHERE album_id = :album',
            ['album' => $albumId],
        ));
    }
}
