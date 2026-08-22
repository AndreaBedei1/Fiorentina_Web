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

    // Un prodotto a catalogo e un prodotto che si puo ordinare.
    private const PUBLISHED_CONDITION = 'p.deleted_at IS NULL';

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

    public function findPublished(int $id): ?Product
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM products p ' . self::JOIN . '
             WHERE p.id = :id AND ' . self::PUBLISHED_CONDITION,
            ['id' => $id],
        );

        if ($row === null) {
            return null;
        }

        $id = (int) $row['id'];

        return Product::fromRow($row, $this->imagesFor([$id])[$id] ?? [], $this->variantsFor([$id])[$id] ?? []);
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
            'default' => 'p.created_at DESC',
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
    /** @return Paginator<Product> */
    public function paginateForAdmin(int $page, int $perPage = 20, string $basePath = ''): Paginator
    {
        $paginator = $this->paginateQuery(
            'SELECT ' . self::COLUMNS . ' FROM products p ' . self::JOIN . '
             WHERE p.deleted_at IS NULL
             ORDER BY p.created_at DESC',
            'SELECT COUNT(*) FROM products p WHERE p.deleted_at IS NULL',
            [],
            $page,
            $perPage,
            static fn (array $row): array => $row,
            $basePath,
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
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->db->insertInto('products', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('products', $id, $data) >= 0;
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
            'DELETE FROM products WHERE id = :id',
            ['id' => $id],
        ) > 0;
    }

    /** @param array<string, mixed> $data */
    public function addImage(array $data): int
    {
        $data['created_at'] = $this->now();

        return $this->db->insertInto('product_images', $data);
    }

    /** Quante fotografie ha un prodotto in questo momento. */
    public function countImages(int $productId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM product_images WHERE product_id = :product',
            ['product' => $productId],
        );
    }

    /**
     * Toglie una fotografia dal prodotto.
     *
     * L'immagine deve appartenere davvero a quel prodotto: l'id arriva
     * dall'indirizzo, e un indirizzo si puo scrivere a mano.
     *
     * Se quella eliminata era la principale, la principale diventa la prima
     * rimasta. Senza, il prodotto resterebbe con delle fotografie ma senza
     * nessuna eletta a copertina, e nel pannello sparirebbe la stellina.
     */
    public function deleteImage(int $productId, int $imageId): ?string
    {
        $row = $this->db->selectOne(
            'SELECT storage_key, is_primary FROM product_images WHERE id = :id AND product_id = :product',
            ['id' => $imageId, 'product' => $productId],
        );

        if ($row === null) {
            return null;
        }

        $this->db->transaction(function () use ($productId, $imageId, $row): void {
            $this->db->statement('DELETE FROM product_images WHERE id = :id', ['id' => $imageId]);

            if ((int) $row['is_primary'] !== 1) {
                return;
            }

            $this->db->statement(
                'UPDATE product_images SET is_primary = 1
                   WHERE product_id = :product
                   ORDER BY sort_order ASC, id ASC
                   LIMIT 1',
                ['product' => $productId],
            );
        });

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
    /**
     * Chiavi ed estensioni delle immagini, per cancellarne i file.
     *
     * @return list<array{storage_key: string, extension: string}>
     */
    public function fileImmaginiDi(int $productId): array
    {
        return array_map(
            static fn (array $r): array => [
                'storage_key' => (string) $r['storage_key'],
                'extension' => (string) ($r['extension'] ?? 'jpg'),
            ],
            $this->db->select(
                'SELECT storage_key, extension FROM product_images WHERE product_id = :product',
                ['product' => $productId],
            ),
        );
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

    /** Prodotti a catalogo, per la sitemap. @return list<array{key: string, updated_at: string}> */
    public function publishedForSitemap(): array
    {
        return array_map(
            static fn (array $r): array => [
                'key' => Product::fromRow($r)->urlKey(),
                'updated_at' => (string) $r['updated_at'],
            ],
            $this->db->select(
                'SELECT p.id, p.name, p.updated_at FROM products p
                 WHERE ' . self::PUBLISHED_CONDITION . '
                 ORDER BY p.created_at DESC LIMIT 2000'
            ),
        );
    }
}
