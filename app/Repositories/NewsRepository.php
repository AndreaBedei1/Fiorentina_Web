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
    private const LIST_COLUMNS = 'n.id, n.title, n.excerpt, n.image_key, n.image_alt,
        n.author_id, n.published_at,
        n.created_at, n.updated_at';

    private const FULL_COLUMNS = 'n.*';

    // Una notizia esiste, quindi si vede: non c'e uno stato che la trattenga.
    private const PUBLISHED_CONDITION = 'n.deleted_at IS NULL';

    public function find(int $id): ?News
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::FULL_COLUMNS . ' FROM news n
             WHERE n.id = :id AND n.deleted_at IS NULL',
            ['id' => $id],
        );

        return $row === null ? null : News::fromRow($row);
    }

    public function findPublished(int $id): ?News
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::FULL_COLUMNS . ' FROM news n
             WHERE n.id = :id AND ' . self::PUBLISHED_CONDITION,
            ['id' => $id],
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
    public function paginateForAdmin(int $page, int $perPage = 20, string $basePath = ''): Paginator
    {
        return $this->paginateQuery(
            'SELECT ' . self::LIST_COLUMNS . ' FROM news n
             WHERE n.deleted_at IS NULL
             ORDER BY n.published_at DESC',
            'SELECT COUNT(*) FROM news n WHERE n.deleted_at IS NULL',
            [],
            $page,
            $perPage,
            News::fromRow(...),
            $basePath,
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = $this->now();
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        // Ogni notizia ha una data, e senza indicazioni e quella di adesso:
        // e la colonna su cui l'elenco pubblico ordina, non puo restare vuota.
        $data['published_at'] = $data['published_at'] ?? $now;

        return $this->db->insertInto('news', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('news', $id, $data) >= 0;
    }

    /**
     * Elimina davvero la riga.
     *
     * Prima era un soft delete: la riga restava a database con una data di
     * cancellazione, invisibile ovunque ma presente per sempre, e i file
     * delle immagini restavano su disco. Un archivio che cresce di cose che
     * nessuno rivedra mai, e che nessuna pagina permette di ripescare: il
     * ripristino esisteva solo in teoria, andando a mano sul database.
     *
     * Chi elimina si aspetta che sparisca. Le eccezioni restano dove servono
     * davvero: gli ordini non si eliminano affatto, e le righe d'ordine
     * conservano gia una copia di nome e prezzo dell'articolo.
     */
    public function delete(int $id): bool
    {
        return $this->db->statement(
            'DELETE FROM news WHERE id = :id',
            ['id' => $id],
        ) > 0;
    }

    /** Notizie pubblicate, per la sitemap. @return list<array{key: string, updated_at: string}> */
    public function publishedForSitemap(): array
    {
        return array_map(
            static fn (array $r): array => [
                'key' => News::fromRow($r)->urlKey(),
                'updated_at' => (string) $r['updated_at'],
            ],
            $this->db->select(
                'SELECT n.id, n.title, n.updated_at FROM news n
                 WHERE ' . self::PUBLISHED_CONDITION . '
                 ORDER BY n.published_at DESC LIMIT 2000'
            ),
        );
    }
}
