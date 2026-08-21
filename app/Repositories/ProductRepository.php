<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Support\Str;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;

/**
 * Accesso al catalogo merchandising.
 *
 * Immagini e varianti non vengono caricate con una query per prodotto: si
 * recuperano in blocco a partire dagli ID della pagina corrente. E la
 * differenza fra tre query e sessanta su una griglia di venti articoli.
 */
final class ProductRepository extends BaseRepository
{
    protected string $table = 'products';

    private const JOIN = 'LEFT JOIN product_categories pc ON pc.id = p.category_id';

    private const COLUMNS = 'p.*, pc.name AS category_name, pc.slug AS category_slug';

    private const PUBLISHED_CONDITION = "p.deleted_at IS NULL AND p.status = 'published'";

    public function find(int $id): ?Product
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM products p ' . self::JOIN . '
             WHERE p.id = :id AND p.deleted_at IS NULL',
            ['id' => $id],
        );

        if ($row === null) {
            return null;
        }

        return Product::fromRow($row, $this->imagesFor([$id])[$id] ?? [], $this->variantsFor([$id])[$id] ?? []);
    }

    public function findPublishedBySlug(string $slug): ?Product
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM products p ' . self::JOIN . '
             WHERE p.slug = :slug AND ' . self::PUBLISHED_CONDITION,
            ['slug' => $slug],
        );

        if ($row === null) {
            return null;
        }

        $id = (int) $row['id'];

        return Product::fromRow($row, $this->imagesFor([$id])[$id] ?? [], $this->variantsFor([$id])[$id] ?? []);
    }

    /** @return list<Product> */
    public function featured(int $limit = 4): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM products p ' . self::JOIN . '
             WHERE ' . self::PUBLISHED_CONDITION . ' AND p.is_featured = 1
             ORDER BY p.sort_order ASC, p.created_at DESC
             LIMIT ' . max(1, min(12, $limit)),
        );

        // Se non ci sono articoli in evidenza mostriamo comunque i più recenti:
        // una sezione vuota in homepage e peggio di una scelta automatica.
        if ($rows === []) {
            $rows = $this->db->select(
                'SELECT ' . self::COLUMNS . ' FROM products p ' . self::JOIN . '
                 WHERE ' . self::PUBLISHED_CONDITION . '
                 ORDER BY p.created_at DESC
                 LIMIT ' . max(1, min(12, $limit)),
            );
        }

        return $this->hydrateMany($rows);
    }

    /** @return Paginator<Product> */
    public function paginatePublished(
        int $page,
        int $perPage = 12,
        ?string $categorySlug = null,
        string $search = '',
        ?string $sort = null,
        string $basePath = '',
    ): Paginator {
        $conditions = [self::PUBLISHED_CONDITION];
        $bindings = [];

        if ($categorySlug !== null && $categorySlug !== '') {
            $conditions[] = 'pc.slug = :category';
            $bindings['category'] = $categorySlug;
        }

        if (trim($search) !== '') {
            $conditions[] = '(p.name LIKE :search OR p.short_description LIKE :search)';
            $bindings['search'] = '%' . trim($search) . '%';
        }

        $where = implode(' AND ', $conditions);

        $orderBy = $this->safeOrderBy($sort, [
            'recenti' => 'p.created_at DESC',
            'prezzo-asc' => 'p.price ASC',
            'prezzo-desc' => 'p.price DESC',
            'nome' => 'p.name ASC',
            'default' => 'p.sort_order ASC, p.created_at DESC',
        ], 'default');

        $paginator = $this->paginateQuery(
            'SELECT ' . self::COLUMNS . ' FROM products p ' . self::JOIN . '
             WHERE ' . $where . ' ORDER BY ' . $orderBy,
            'SELECT COUNT(*) FROM products p ' . self::JOIN . ' WHERE ' . $where,
            $bindings,
            $page,
            $perPage,
            static fn (array $row): array => $row,
            $basePath,
            array_filter([
                'categoria' => $categorySlug,
                'q' => $search !== '' ? $search : null,
                'ordina' => $sort,
            ]),
        );

        return $paginator->withItems($this->hydrateMany($paginator->items()));
    }

    /** @return Paginator<Product> */
    public function paginateForAdmin(
        int $page,
        int $perPage = 20,
        ?string $status = null,
        string $search = '',
        string $basePath = '',
    ): Paginator {
        $conditions = ['p.deleted_at IS NULL'];
        $bindings = [];

        if ($status !== null && $status !== '') {
            $conditions[] = 'p.status = :status';
            $bindings['status'] = $status;
        }

        if (trim($search) !== '') {
            $conditions[] = 'p.name LIKE :search';
            $bindings['search'] = '%' . trim($search) . '%';
        }

        $where = implode(' AND ', $conditions);

        $paginator = $this->paginateQuery(
            'SELECT ' . self::COLUMNS . ' FROM products p ' . self::JOIN . '
             WHERE ' . $where . ' ORDER BY p.sort_order ASC, p.created_at DESC',
            'SELECT COUNT(*) FROM products p WHERE ' . $where,
            $bindings,
            $page,
            $perPage,
            static fn (array $row): array => $row,
            $basePath,
            array_filter(['stato' => $status, 'q' => $search !== '' ? $search : null]),
        );

        return $paginator->withItems($this->hydrateMany($paginator->items()));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Product>
     */
    private function hydrateMany(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $images = $this->imagesFor($ids);
        $variants = $this->variantsFor($ids);

        return array_map(
            static fn (array $row): Product => Product::fromRow(
                $row,
                $images[(int) $row['id']] ?? [],
                $variants[(int) $row['id']] ?? [],
            ),
            $rows,
        );
    }

    /**
     * @param list<int> $productIds
     * @return array<int, list<ProductImage>>
     */
    private function imagesFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));

        $rows = $this->db->select(
            'SELECT * FROM product_images WHERE product_id IN (' . $placeholders . ')
             ORDER BY is_primary DESC, sort_order ASC, id ASC',
            array_values($productIds),
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['product_id']][] = ProductImage::fromRow($row);
        }

        return $grouped;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, list<ProductVariant>>
     */
    private function variantsFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));

        $rows = $this->db->select(
            'SELECT * FROM product_variants WHERE product_id IN (' . $placeholders . ')
             ORDER BY sort_order ASC, id ASC',
            array_values($productIds),
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['product_id']][] = ProductVariant::fromRow($row);
        }

        return $grouped;
    }

    // -----------------------------------------------------------------------
    //  Scrittura
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = $this->now();
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? Str::slug((string) ($data['name'] ?? '')));
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->db->insertInto('products', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $id);
        }

        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('products', $id, $data) >= 0;
    }

    public function delete(int $id): bool
    {
        return $this->softDelete($id);
    }

    /** @param array<string, mixed> $data */
    public function addImage(array $data): int
    {
        $data['created_at'] = $this->now();

        return $this->db->insertInto('product_images', $data);
    }

    public function deleteImage(int $imageId): ?string
    {
        $row = $this->db->selectOne('SELECT storage_key FROM product_images WHERE id = :id', ['id' => $imageId]);

        if ($row === null) {
            return null;
        }

        $this->db->statement('DELETE FROM product_images WHERE id = :id', ['id' => $imageId]);

        return (string) $row['storage_key'];
    }

    public function setPrimaryImage(int $productId, int $imageId): void
    {
        $this->db->transaction(function () use ($productId, $imageId): void {
            $this->db->statement(
                'UPDATE product_images SET is_primary = 0 WHERE product_id = :product',
                ['product' => $productId],
            );
            $this->db->statement(
                'UPDATE product_images SET is_primary = 1 WHERE id = :id AND product_id = :product',
                ['id' => $imageId, 'product' => $productId],
            );
        });
    }

    /** @return list<string> */
    public function imageKeysFor(int $productId): array
    {
        return array_map('strval', $this->db->column(
            'SELECT storage_key FROM product_images WHERE product_id = :product',
            ['product' => $productId],
        ));
    }

    /**
     * Sostituisce integralmente le varianti di un prodotto.
     *
     * @param list<array<string, mixed>> $variants
     */
    public function replaceVariants(int $productId, array $variants): void
    {
        $this->db->transaction(function () use ($productId, $variants): void {
            $this->db->statement('DELETE FROM product_variants WHERE product_id = :product', ['product' => $productId]);

            $now = $this->now();
            $position = 0;

            foreach ($variants as $variant) {
                $label = trim((string) ($variant['label'] ?? ''));

                if ($label === '') {
                    continue;
                }

                $this->db->insertInto('product_variants', [
                    'product_id' => $productId,
                    'label' => $label,
                    'quantity' => $variant['quantity'] ?? null,
                    'is_available' => (int) (bool) ($variant['is_available'] ?? true),
                    'sort_order' => $position++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function findVariant(int $variantId, int $productId): ?ProductVariant
    {
        $row = $this->db->selectOne(
            'SELECT * FROM product_variants WHERE id = :id AND product_id = :product',
            ['id' => $variantId, 'product' => $productId],
        );

        return $row === null ? null : ProductVariant::fromRow($row);
    }

    /** @return array{total: int, published: int, out_of_stock: int} */
    public function statistics(): array
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'published') AS published,
                    SUM(availability = 'out_of_stock') AS out_of_stock
             FROM products WHERE deleted_at IS NULL"
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'published' => (int) ($row['published'] ?? 0),
            'out_of_stock' => (int) ($row['out_of_stock'] ?? 0),
        ];
    }

    /** @return list<array{slug: string, updated_at: string}> */
    public function publishedForSitemap(): array
    {
        return array_map(
            static fn (array $r): array => ['slug' => (string) $r['slug'], 'updated_at' => (string) $r['updated_at']],
            $this->db->select(
                'SELECT p.slug, p.updated_at FROM products p
                 WHERE ' . self::PUBLISHED_CONDITION . ' ORDER BY p.created_at DESC LIMIT 1000'
            ),
        );
    }
}
