<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Page;
use App\Models\PageBlock;

/** Accesso alle pagine editoriali e ai loro blocchi. */
final class PageRepository extends BaseRepository
{
    protected string $table = 'pages';

    public function find(int $id, bool $withBlocks = true): ?Page
    {
        $row = $this->db->selectOne('SELECT * FROM pages WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            return null;
        }

        return Page::fromRow($row, $withBlocks ? $this->blocksFor($id) : []);
    }

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

    /** @return list<Page> */
    public function all(): array
    {
        return array_map(
            static fn (array $row): Page => Page::fromRow($row),
            $this->db->select('SELECT * FROM pages ORDER BY is_system DESC, title ASC'),
        );
    }

    /** @return list<PageBlock> */
    public function blocksFor(int $pageId): array
    {
        return array_map(
            PageBlock::fromRow(...),
            $this->db->select(
                'SELECT * FROM page_blocks WHERE page_id = :page ORDER BY sort_order ASC, id ASC',
                ['page' => $pageId],
            ),
        );
    }

    public function findBlock(int $blockId): ?PageBlock
    {
        $row = $this->db->selectOne('SELECT * FROM page_blocks WHERE id = :id', ['id' => $blockId]);

        return $row === null ? null : PageBlock::fromRow($row);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('pages', $id, $data) >= 0;
    }

    /** @param array<string, mixed> $data */
    public function createBlock(int $pageId, array $data): int
    {
        $now = $this->now();
        $data['page_id'] = $pageId;
        $data['sort_order'] = $data['sort_order'] ?? $this->nextBlockOrder($pageId);
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->db->insertInto('page_blocks', $data);
    }

    /** @param array<string, mixed> $data */
    public function updateBlock(int $blockId, array $data): bool
    {
        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('page_blocks', $blockId, $data) >= 0;
    }

    public function deleteBlock(int $blockId): bool
    {
        return $this->db->statement('DELETE FROM page_blocks WHERE id = :id', ['id' => $blockId]) > 0;
    }

    /** @param list<int> $orderedIds */
    public function reorderBlocks(int $pageId, array $orderedIds): void
    {
        $this->db->transaction(function () use ($pageId, $orderedIds): void {
            $position = 1;

            foreach ($orderedIds as $blockId) {
                $this->db->statement(
                    'UPDATE page_blocks SET sort_order = :position WHERE id = :id AND page_id = :page',
                    ['position' => $position++, 'id' => (int) $blockId, 'page' => $pageId],
                );
            }
        });
    }

    private function nextBlockOrder(int $pageId): int
    {
        return (int) $this->db->scalar(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM page_blocks WHERE page_id = :page',
            ['page' => $pageId],
        );
    }
}
