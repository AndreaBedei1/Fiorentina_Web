<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Models\ContactMessage;

/** Messaggi ricevuti dal modulo contatti. */
final class ContactMessageRepository extends BaseRepository
{
    protected string $table = 'contact_messages';

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $data['created_at'] = $this->now();

        return $this->db->insertInto('contact_messages', $data);
    }

    public function find(int $id): ?ContactMessage
    {
        $row = $this->db->selectOne('SELECT * FROM contact_messages WHERE id = :id', ['id' => $id]);

        return $row === null ? null : ContactMessage::fromRow($row);
    }

    /** @return Paginator<ContactMessage> */
    public function paginate(int $page, int $perPage = 20, ?string $status = null, string $basePath = ''): Paginator
    {
        $conditions = ['1 = 1'];
        $bindings = [];

        if ($status !== null && $status !== '') {
            $conditions[] = 'status = :status';
            $bindings['status'] = $status;
        }

        $where = implode(' AND ', $conditions);

        return $this->paginateQuery(
            'SELECT * FROM contact_messages WHERE ' . $where . ' ORDER BY created_at DESC',
            'SELECT COUNT(*) FROM contact_messages WHERE ' . $where,
            $bindings,
            $page,
            $perPage,
            ContactMessage::fromRow(...),
            $basePath,
            array_filter(['stato' => $status]),
        );
    }

    public function markRead(int $id, int $userId): void
    {
        $this->db->statement(
            "UPDATE contact_messages SET status = 'read', read_at = :now, read_by = :user
             WHERE id = :id AND status = 'new'",
            ['now' => $this->now(), 'user' => $userId, 'id' => $id],
        );
    }

    public function updateStatus(int $id, string $status): bool
    {
        return $this->db->updateWhereId('contact_messages', $id, ['status' => $status]) > 0;
    }

    public function delete(int $id): bool
    {
        return $this->db->statement('DELETE FROM contact_messages WHERE id = :id', ['id' => $id]) > 0;
    }

    public function countNew(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'");
    }
}
