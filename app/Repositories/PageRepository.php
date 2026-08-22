<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Page;
use App\Models\PageBlock;

/**
 * Le pagine editoriali e i loro blocchi.
 *
 * Da qui si legge soltanto. Le pagine non si modificano piu dal pannello: sono
 * quattro testi che si scrivono una volta - chi siamo, diventa socio, privacy,
 * cookie - e cambiarli e una cosa da fare con calma sul database, non fra una
 * notizia e un ordine.
 */
final class PageRepository extends BaseRepository
{
    protected string $table = 'pages';

    public function findBySlug(string $slug, bool $onlyPublished = true): ?Page
    {
        $sql = 'SELECT * FROM pages WHERE slug = :slug';

        if ($onlyPublished) {
            $sql .= " AND status = 'published'";
        }

        $row = $this->db->selectOne($sql, ['slug' => $slug]);

        if ($row === null) {
            return null;
        }

        return Page::fromRow($row, $this->blocksFor((int) $row['id']));
    }

    /** @return list<PageBlock> */
    private function blocksFor(int $pageId): array
    {
        return array_map(
            PageBlock::fromRow(...),
            $this->db->select(
                'SELECT * FROM page_blocks WHERE page_id = :page ORDER BY sort_order ASC, id ASC',
                ['page' => $pageId],
            ),
        );
    }
}
