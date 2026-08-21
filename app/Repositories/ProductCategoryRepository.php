<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Support\Str;
use App\Models\ProductCategory;

/** Categorie del catalogo merchandising. */
final class ProductCategoryRepository extends BaseRepository
{
    protected string $table = 'product_categories';

    /** @return list<ProductCategory> */
    public function all(): array
    {
        return array_map(
            ProductCategory::fromRow(...),
            $this->db->select('SELECT * FROM product_categories ORDER BY sort_order ASC, name ASC'),
        );
    }

    /**
     * Categorie visibili con almeno un prodotto pubblicato: i filtri del
     * catalogo non devono mostrare voci che portano a una pagina vuota.
     *
     * @return list<ProductCategory>
     */
    public function activeWithProducts(): array
    {
        $rows = $this->db->select(
            "SELECT c.*, COUNT(p.id) AS products_count
             FROM product_categories c
             JOIN products p ON p.category_id = c.id AND p.deleted_at IS NULL
             WHERE c.status = 'active'
             GROUP BY c.id
             ORDER BY c.sort_order ASC, c.name ASC"
        );

        return array_map(ProductCategory::fromRow(...), $rows);
    }

    public function find(int $id): ?ProductCategory
    {
        $row = $this->db->selectOne('SELECT * FROM product_categories WHERE id = :id', ['id' => $id]);

        return $row === null ? null : ProductCategory::fromRow($row);
    }

    public function findBySlug(string $slug): ?ProductCategory
    {
        $row = $this->db->selectOne('SELECT * FROM product_categories WHERE slug = :slug', ['slug' => $slug]);

        return $row === null ? null : ProductCategory::fromRow($row);
    }

    /** @return array<int, string> */
    public function options(): array
    {
        return array_map('strval', $this->db->pairs(
            'SELECT id, name FROM product_categories ORDER BY sort_order ASC, name ASC'
        ));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = $this->now();
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? Str::slug((string) ($data['name'] ?? '')));
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->db->insertInto('product_categories', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $id);
        }

        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('product_categories', $id, $data) >= 0;
    }

    public function delete(int $id): bool
    {
        return $this->db->statement('DELETE FROM product_categories WHERE id = :id', ['id' => $id]) > 0;
    }
}
