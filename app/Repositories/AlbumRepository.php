<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Support\Str;
use App\Models\Album;

/**
 * Accesso agli album fotografici.
 *
 * L'archivio del gruppo e ampio: gli elenchi non caricano mai le fotografie
 * degli album, ma solo la copertina, ricavata con un LEFT JOIN mirato.
 */
final class AlbumRepository extends BaseRepository
{
    protected string $table = 'albums';

    private const COVER_JOIN = 'LEFT JOIN photos cp ON cp.id = a.cover_photo_id';

    private const COVER_COLUMNS = 'cp.storage_key AS cover_storage_key, cp.extension AS cover_extension,
        cp.alt_text AS cover_alt_text, cp.width AS cover_width, cp.height AS cover_height';

    private const PUBLISHED_CONDITION = "a.deleted_at IS NULL AND a.status = 'published'";

    public function find(int $id): ?Album
    {
        $row = $this->db->selectOne(
            'SELECT a.*, ' . self::COVER_COLUMNS . ' FROM albums a ' . self::COVER_JOIN . '
             WHERE a.id = :id AND a.deleted_at IS NULL',
            ['id' => $id],
        );

        return $row === null ? null : Album::fromRow($row);
    }

    public function findPublishedBySlug(string $slug): ?Album
    {
        $row = $this->db->selectOne(
            'SELECT a.*, ' . self::COVER_COLUMNS . ' FROM albums a ' . self::COVER_JOIN . '
             WHERE a.slug = :slug AND ' . self::PUBLISHED_CONDITION,
            ['slug' => $slug],
        );

        return $row === null ? null : Album::fromRow($row);
    }

    /** @return list<Album> */
    public function latestPublished(int $limit = 4): array
    {
        $rows = $this->db->select(
            'SELECT a.*, ' . self::COVER_COLUMNS . ' FROM albums a ' . self::COVER_JOIN . '
             WHERE ' . self::PUBLISHED_CONDITION . ' AND a.photos_count > 0
             ORDER BY COALESCE(a.event_date, a.created_at) DESC
             LIMIT ' . max(1, min(20, $limit)),
        );

        return array_map(Album::fromRow(...), $rows);
    }

    /** @return Paginator<Album> */
    public function paginatePublished(
        int $page,
        int $perPage = 12,
        ?int $year = null,
        ?string $category = null,
        string $basePath = '',
    ): Paginator {
        $conditions = [self::PUBLISHED_CONDITION];
        $bindings = [];

        if ($year !== null) {
            $conditions[] = 'a.year = :year';
            $bindings['year'] = $year;
        }

        if ($category !== null && in_array($category, Album::CATEGORIES, true)) {
            $conditions[] = 'a.category = :category';
            $bindings['category'] = $category;
        }

        $where = implode(' AND ', $conditions);

        return $this->paginateQuery(
            'SELECT a.*, ' . self::COVER_COLUMNS . ' FROM albums a ' . self::COVER_JOIN . '
             WHERE ' . $where . '
             ORDER BY a.sort_order ASC, COALESCE(a.event_date, a.created_at) DESC',
            'SELECT COUNT(*) FROM albums a WHERE ' . $where,
            $bindings,
            $page,
            $perPage,
            Album::fromRow(...),
            $basePath,
            array_filter(['anno' => $year, 'categoria' => $category]),
        );
    }

    /** @return Paginator<Album> */
    public function paginateForAdmin(int $page, int $perPage = 20, string $search = '', string $basePath = ''): Paginator
    {
        $conditions = ['a.deleted_at IS NULL'];
        $bindings = [];

        if (trim($search) !== '') {
            $conditions[] = 'a.title LIKE :search';
            $bindings['search'] = '%' . trim($search) . '%';
        }

        $where = implode(' AND ', $conditions);

        return $this->paginateQuery(
            'SELECT a.*, ' . self::COVER_COLUMNS . ' FROM albums a ' . self::COVER_JOIN . '
             WHERE ' . $where . ' ORDER BY COALESCE(a.event_date, a.created_at) DESC',
            'SELECT COUNT(*) FROM albums a WHERE ' . $where,
            $bindings,
            $page,
            $perPage,
            Album::fromRow(...),
            $basePath,
            array_filter(['q' => $search !== '' ? $search : null]),
        );
    }

    /** Anni disponibili per il filtro della galleria. @return list<int> */
    public function availableYears(): array
    {
        $years = $this->db->column(
            'SELECT DISTINCT a.year FROM albums a
             WHERE ' . self::PUBLISHED_CONDITION . ' AND a.year IS NOT NULL
             ORDER BY a.year DESC'
        );

        return array_map('intval', $years);
    }

    /** @return array<string, int> Categorie con almeno un album pubblicato. */
    public function categoriesWithCounts(): array
    {
        $rows = $this->db->select(
            'SELECT a.category, COUNT(*) AS total FROM albums a
             WHERE ' . self::PUBLISHED_CONDITION . '
             GROUP BY a.category ORDER BY total DESC'
        );

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row['category']] = (int) $row['total'];
        }

        return $result;
    }

    /** @return list<Album> Album pubblicati, per i menu a tendina dell'admin. */
    public function allForSelect(): array
    {
        return array_map(
            Album::fromRow(...),
            $this->db->select(
                'SELECT a.* FROM albums a WHERE a.deleted_at IS NULL
                 ORDER BY COALESCE(a.event_date, a.created_at) DESC'
            ),
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = $this->now();
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? Str::slug((string) ($data['title'] ?? '')));
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->db->insertInto('albums', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $id);
        }

        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('albums', $id, $data) >= 0;
    }

    public function delete(int $id): bool
    {
        return $this->softDelete($id);
    }

    /**
     * Riallinea il contatore delle fotografie e, se manca, sceglie una copertina.
     * Chiamato dopo ogni upload o eliminazione, cosi il valore denormalizzato
     * non si disallinea mai in silenzio.
     */
    public function refreshCounters(int $albumId): void
    {
        $this->db->statement(
            "UPDATE albums a
             SET a.photos_count = (
                 SELECT COUNT(*) FROM photos p WHERE p.album_id = a.id AND p.status = 'published'
             )
             WHERE a.id = :id",
            ['id' => $albumId],
        );

        $this->db->statement(
            "UPDATE albums a
             SET a.cover_photo_id = (
                 SELECT p.id FROM photos p
                 WHERE p.album_id = a.id AND p.status = 'published'
                 ORDER BY p.sort_order ASC, p.id ASC LIMIT 1
             )
             WHERE a.id = :id
               AND (a.cover_photo_id IS NULL
                    OR NOT EXISTS (
                        SELECT 1 FROM photos p2
                        WHERE p2.id = a.cover_photo_id AND p2.album_id = a.id AND p2.status = 'published'
                    ))",
            ['id' => $albumId],
        );
    }

    /** @return array{albums: int, photos: int} */
    public function statistics(): array
    {
        $albums = (int) $this->db->scalar('SELECT COUNT(*) FROM albums WHERE deleted_at IS NULL');
        $photos = (int) $this->db->scalar('SELECT COUNT(*) FROM photos');

        return ['albums' => $albums, 'photos' => $photos];
    }

    /** @return list<array{slug: string, updated_at: string}> */
    public function publishedForSitemap(): array
    {
        return array_map(
            static fn (array $r): array => ['slug' => (string) $r['slug'], 'updated_at' => (string) $r['updated_at']],
            $this->db->select(
                'SELECT a.slug, a.updated_at FROM albums a
                 WHERE ' . self::PUBLISHED_CONDITION . ' ORDER BY a.event_date DESC LIMIT 1000'
            ),
        );
    }
}
