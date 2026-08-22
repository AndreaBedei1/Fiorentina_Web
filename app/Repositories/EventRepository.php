<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Support\Str;
use App\Models\Event;

/** Accesso agli eventi del gruppo. */
final class EventRepository extends BaseRepository
{
    protected string $table = 'events';

    private const JOIN = 'LEFT JOIN event_categories c ON c.id = e.category_id';

    private const CATEGORY_COLUMNS = 'c.name AS category_name, c.slug AS category_slug,
        c.icon AS category_icon, c.color AS category_color';

    /** Il campo `description` resta fuori dagli elenchi: serve solo nel dettaglio. */
    private const LIST_COLUMNS = 'e.id, e.title, e.short_description, e.category_id,
        e.starts_at, e.ends_at, e.location_name, e.city, e.address, e.meeting_point, e.meeting_at,
        e.image_key, e.cost, e.cost_note, e.limited_seats, e.seats,
        e.created_at, e.updated_at, ' . self::CATEGORY_COLUMNS;

    // Un evento in calendario e un evento che si vede: non c'e uno stato
    // che lo trattenga.
    private const PUBLISHED_CONDITION = 'e.deleted_at IS NULL';

    public function find(int $id): ?Event
    {
        $row = $this->db->selectOne(
            'SELECT e.*, ' . self::CATEGORY_COLUMNS . ' FROM events e ' . self::JOIN . '
             WHERE e.id = :id AND e.deleted_at IS NULL',
            ['id' => $id],
        );

        return $row === null ? null : Event::fromRow($row);
    }

    public function findPublished(int $id): ?Event
    {
        $row = $this->db->selectOne(
            'SELECT e.*, ' . self::CATEGORY_COLUMNS . ' FROM events e ' . self::JOIN . '
             WHERE e.id = :id AND ' . self::PUBLISHED_CONDITION,
            ['id' => $id],
        );

        return $row === null ? null : Event::fromRow($row);
    }

    /**
     * Prossimi appuntamenti pubblicati.
     *
     * Il confronto usa la fine dell'evento quando presente: una trasferta che
     * dura due giorni resta "in corso" anche il secondo giorno.
     *
     * @return list<Event>
     */
    public function upcoming(int $limit = 4): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::LIST_COLUMNS . ' FROM events e ' . self::JOIN . '
             WHERE ' . self::PUBLISHED_CONDITION . '
               AND COALESCE(e.ends_at, e.starts_at) >= NOW()
             ORDER BY e.starts_at ASC
             LIMIT ' . max(1, min(50, $limit)),
        );

        return array_map(Event::fromRow(...), $rows);
    }

    /**
     * Eventi in un intervallo di date: alimenta la pagina calendario.
     *
     * @return list<Event>
     */
    public function betweenDates(string $from, string $to): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::LIST_COLUMNS . ' FROM events e ' . self::JOIN . '
             WHERE ' . self::PUBLISHED_CONDITION . '
               AND e.starts_at >= :from AND e.starts_at < :to
             ORDER BY e.starts_at ASC',
            ['from' => $from, 'to' => $to],
        );

        return array_map(Event::fromRow(...), $rows);
    }

    /** @return Paginator<Event> */
    public function paginatePublished(
        int $page,
        int $perPage = 9,
        bool $past = false,
        ?string $categorySlug = null,
        string $basePath = '',
    ): Paginator {
        $conditions = [self::PUBLISHED_CONDITION];
        $bindings = [];

        $conditions[] = $past
            ? 'COALESCE(e.ends_at, e.starts_at) < NOW()'
            : 'COALESCE(e.ends_at, e.starts_at) >= NOW()';

        if ($categorySlug !== null && $categorySlug !== '') {
            $conditions[] = 'c.slug = :category';
            $bindings['category'] = $categorySlug;
        }

        $where = implode(' AND ', $conditions);
        $order = $past ? 'e.starts_at DESC' : 'e.starts_at ASC';

        return $this->paginateQuery(
            'SELECT ' . self::LIST_COLUMNS . ' FROM events e ' . self::JOIN . '
             WHERE ' . $where . ' ORDER BY ' . $order,
            'SELECT COUNT(*) FROM events e ' . self::JOIN . ' WHERE ' . $where,
            $bindings,
            $page,
            $perPage,
            Event::fromRow(...),
            $basePath,
            array_filter(['categoria' => $categorySlug, 'passati' => $past ? '1' : null]),
        );
    }

    /**
     * Elenco per il pannello: prima quello che deve ancora succedere.
     *
     * Chi apre questa pagina sta preparando i prossimi appuntamenti, non
     * rileggendo quelli finiti: in cima va il piu vicino, poi i successivi in
     * ordine di data. Sotto restano i passati, dal piu recente indietro, che
     * e l'ordine in cui capita di doverli ritoccare.
     *
     * @return Paginator<Event>
     */
    public function paginateForAdmin(int $page, int $perPage = 20, string $basePath = ''): Paginator
    {
        return $this->paginateQuery(
            'SELECT ' . self::LIST_COLUMNS . ' FROM events e ' . self::JOIN . '
             WHERE e.deleted_at IS NULL
             ORDER BY e.starts_at < NOW(),
                      CASE WHEN e.starts_at >= NOW() THEN e.starts_at END ASC,
                      e.starts_at DESC',
            'SELECT COUNT(*) FROM events e WHERE e.deleted_at IS NULL',
            [],
            $page,
            $perPage,
            Event::fromRow(...),
            $basePath,
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = $this->now();
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->db->insertInto('events', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('events', $id, $data) >= 0;
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
            'DELETE FROM events WHERE id = :id',
            ['id' => $id],
        ) > 0;
    }

    /** Eventi pubblicati, per la sitemap. @return list<array{key: string, updated_at: string}> */
    public function publishedForSitemap(): array
    {
        return array_map(
            static fn (array $r): array => [
                'key' => Event::fromRow($r)->urlKey(),
                'updated_at' => (string) $r['updated_at'],
            ],
            $this->db->select(
                'SELECT e.id, e.title, e.starts_at, e.updated_at FROM events e
                 WHERE ' . self::PUBLISHED_CONDITION . '
                 ORDER BY e.starts_at DESC LIMIT 2000'
            ),
        );
    }
}
