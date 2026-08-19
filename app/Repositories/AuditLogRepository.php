<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Models\AuditLog;

/** Accesso al registro delle azioni amministrative. */
final class AuditLogRepository extends BaseRepository
{
    protected string $table = 'audit_logs';

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $data['created_at'] = $this->now();

        return $this->db->insertInto('audit_logs', $data);
    }

    /** @return Paginator<AuditLog> */
    public function paginate(
        int $page,
        int $perPage = 40,
        ?string $action = null,
        ?int $userId = null,
        string $basePath = '',
    ): Paginator {
        $conditions = ['1 = 1'];
        $bindings = [];

        if ($action !== null && $action !== '') {
            $conditions[] = 'action = :action';
            $bindings['action'] = $action;
        }

        if ($userId !== null) {
            $conditions[] = 'user_id = :user';
            $bindings['user'] = $userId;
        }

        $where = implode(' AND ', $conditions);

        return $this->paginateQuery(
            'SELECT * FROM audit_logs WHERE ' . $where . ' ORDER BY created_at DESC, id DESC',
            'SELECT COUNT(*) FROM audit_logs WHERE ' . $where,
            $bindings,
            $page,
            $perPage,
            AuditLog::fromRow(...),
            $basePath,
            array_filter(['azione' => $action, 'utente' => $userId]),
        );
    }

    /** @return list<AuditLog> */
    public function latest(int $limit = 8): array
    {
        return array_map(
            AuditLog::fromRow(...),
            $this->db->select(
                'SELECT * FROM audit_logs ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(50, $limit))
            ),
        );
    }

    /** @return list<string> Azioni presenti, per il menu a tendina del filtro. */
    public function distinctActions(): array
    {
        return array_map('strval', $this->db->column(
            'SELECT DISTINCT action FROM audit_logs ORDER BY action ASC'
        ));
    }

    /** Elimina le voci piu vecchie del periodo indicato: usata dal cron di pulizia. */
    public function purgeOlderThan(int $days): int
    {
        return $this->db->statement(
            'DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)',
            ['days' => max(30, $days)],
        );
    }
}
