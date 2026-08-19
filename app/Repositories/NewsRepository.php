<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Support\Str;
use App\Models\News;

/** Accesso alle notizie. */
final class NewsRepository extends BaseRepository
{
    protected string $table = 'news';

    /**
     * Colonne selezionate per le viste di elenco.
     * `content` e volutamente escluso: e una MEDIUMTEXT che non serve alle card
     * e che, moltiplicata per una pagina di risultati, pesa parecchio.
     */
    private const LIST_COLUMNS = 'n.id, n.title, n.slug, n.excerpt, n.image_key, n.image_alt,
        n.author_id, n.status, n.published_at, n.is_featured, n.views,
        n.created_at, n.updated_at, u.name AS author_name';

    private const FULL_COLUMNS = 'n.*, u.name AS author_name';

    private const PUBLISHED_CONDITION = "n.deleted_at IS NULL AND n.status = 'published' AND n.published_at <= NOW()";

    public function find(int $id): ?News
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::FULL_COLUMNS . ' FROM news n
             LEFT JOIN users u ON u.id = n.author_id
             WHERE n.id = :id AND n.deleted_at IS NULL',
            ['id' => $id],
        );

        return $row === null ? null : News::fromRow($row);
    }

    public function findPublishedBySlug(string $slug): ?News
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::FULL_COLUMNS . ' FROM news n
             LEFT JOIN users u ON u.id = n.author_id
             WHERE n.slug = :slug AND ' . self::PUBLISHED_CONDITION,
            ['slug' => $slug],
        );

        return $row === null ? null : News::fromRow($row);
    }

    /**
     * Ultime notizie pubblicate, per la homepage.
     *
     * @return list<News>
     */
    public function latestPublished(int $limit = 3): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::LIST_COLUMNS . ' FROM news n
             LEFT JOIN users u ON u.id = n.author_id
             WHERE ' . self::PUBLISHED_CONDITION . '
             ORDER BY n.published_at DESC
             LIMIT ' . max(1, min(20, $limit)),
        );

        return array_map(News::fromRow(...), $rows);
    }

    /** @return Paginator<News> */
    public function paginatePublished(int $page, int $perPage = 9, string $basePath = ''): Paginator
    {
        return $this->paginateQuery(
            'SELECT ' . self::LIST_COLUMNS . ' FROM news n
             LEFT JOIN users u ON u.id = n.author_id
             WHERE ' . self::PUBLISHED_CONDITION . '
             ORDER BY n.published_at DESC',
            'SELECT COUNT(*) FROM news n WHERE ' . self::PUBLISHED_CONDITION,
            [],
            $page,
            $perPage,
            News::fromRow(...),
            $basePath,
        );
    }

    /**
     * Elenco per l'area amministrativa, con filtri di stato e ricerca testuale.
     *
     * @return Paginator<News>
     */
    public function paginateForAdmin(
        int $page,
        int $perPage = 20,
        ?string $status = null,
        string $search = '',
        string $basePath = '',
    ): Paginator {
        $conditions = ['n.deleted_at IS NULL'];
        $bindings = [];

        if ($status !== null && in_array($status, [News::STATUS_DRAFT, News::STATUS_PUBLISHED, News::STATUS_ARCHIVED], true)) {
            $conditions[] = 'n.status = :status';
            $bindings['status'] = $status;
        }

        if (trim($search) !== '') {
            $conditions[] = '(n.title LIKE :search OR n.excerpt LIKE :search)';
            $bindings['search'] = '%' . trim($search) . '%';
        }

        $where = implode(' AND ', $conditions);

        return $this->paginateQuery(
            'SELECT ' . self::LIST_COLUMNS . ' FROM news n
             LEFT JOIN users u ON u.id = n.author_id
             WHERE ' . $where . '
             ORDER BY COALESCE(n.published_at, n.created_at) DESC',
            'SELECT COUNT(*) FROM news n WHERE ' . $where,
            $bindings,
            $page,
            $perPage,
            News::fromRow(...),
            $basePath,
            array_filter(['stato' => $status, 'q' => $search !== '' ? $search : null]),
        );
    }

    /**
     * Altre notizie da suggerire in fondo a un articolo.
     *
     * @return list<News>
     */
    public function relatedTo(int $newsId, int $limit = 3): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::LIST_COLUMNS . ' FROM news n
             LEFT JOIN users u ON u.id = n.author_id
             WHERE ' . self::PUBLISHED_CONDITION . ' AND n.id <> :id
             ORDER BY n.published_at DESC
             LIMIT ' . max(1, min(6, $limit)),
            ['id' => $newsId],
        );

        return array_map(News::fromRow(...), $rows);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = $this->now();
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? Str::slug((string) ($data['title'] ?? '')));
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->db->insertInto('news', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $id);
        }

        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('news', $id, $data) >= 0;
    }

    public function delete(int $id): bool
    {
        return $this->softDelete($id);
    }

    public function incrementViews(int $id): void
    {
        $this->db->statement('UPDATE news SET views = views + 1 WHERE id = :id', ['id' => $id]);
    }

    /** @return array{total: int, published: int, draft: int} */
    public function statistics(): array
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'published') AS published,
                    SUM(status = 'draft') AS draft
             FROM news WHERE deleted_at IS NULL"
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'published' => (int) ($row['published'] ?? 0),
            'draft' => (int) ($row['draft'] ?? 0),
        ];
    }

    /** Slug delle notizie pubblicate, per la sitemap. @return list<array{slug: string, updated_at: string}> */
    public function publishedForSitemap(): array
    {
        return array_map(
            static fn (array $r): array => ['slug' => (string) $r['slug'], 'updated_at' => (string) $r['updated_at']],
            $this->db->select(
                'SELECT n.slug, n.updated_at FROM news n
                 WHERE ' . self::PUBLISHED_CONDITION . '
                 ORDER BY n.published_at DESC LIMIT 2000'
            ),
        );
    }
}
