<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Support\Str;
use App\Models\EventCategory;

/** Categorie degli eventi del gruppo. */
final class EventCategoryRepository extends BaseRepository
{
    protected string $table = 'event_categories';

    /** @return list<EventCategory> */
    public function all(): array
    {
        return array_map(
            EventCategory::fromRow(...),
            $this->db->select('SELECT * FROM event_categories ORDER BY sort_order ASC, name ASC'),
        );
    }

    public function find(int $id): ?EventCategory
    {
        $row = $this->db->selectOne('SELECT * FROM event_categories WHERE id = :id', ['id' => $id]);

        return $row === null ? null : EventCategory::fromRow($row);
    }

    public function findBySlug(string $slug): ?EventCategory
    {
        $row = $this->db->selectOne('SELECT * FROM event_categories WHERE slug = :slug', ['slug' => $slug]);

        return $row === null ? null : EventCategory::fromRow($row);
    }

    /** @return array<int, string> Mappa id => nome, per i menu a tendina. */
    public function options(): array
    {
        return array_map('strval', $this->db->pairs(
            'SELECT id, name FROM event_categories ORDER BY sort_order ASC, name ASC'
        ));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = $this->now();
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? Str::slug((string) ($data['name'] ?? '')));
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->db->insertInto('event_categories', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $id);
        }

        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('event_categories', $id, $data) >= 0;
    }

    /** La categoria si elimina davvero: gli eventi collegati restano, senza categoria. */
    public function delete(int $id): bool
    {
        return $this->db->statement('DELETE FROM event_categories WHERE id = :id', ['id' => $id]) > 0;
    }
}
