<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Models\User;

/**
 * Accesso agli account amministratore.
 *
 * Tutte le letture escludono per default gli account soft-deleted: un
 * amministratore rimosso non deve poter accedere ne comparire negli elenchi,
 * ma resta nel database perché l'audit log continui ad avere senso.
 */
final class UserRepository extends BaseRepository
{
    protected string $table = 'users';

    private const COLUMNS = 'id, name, email, password_hash, role, status, phone,
        last_login_at, last_login_ip, password_changed_at, sessions_valid_after,
        created_by, created_at, updated_at, deleted_at';

    public function find(int $id): ?User
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        );

        return $row === null ? null : User::fromRow($row);
    }

    /** Include gli account eliminati: serve all'audit log per risolvere i nomi. */
    public function findEvenIfDeleted(int $id): ?User
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE id = :id',
            ['id' => $id],
        );

        return $row === null ? null : User::fromRow($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE email = :email AND deleted_at IS NULL',
            ['email' => mb_strtolower(trim($email))],
        );

        return $row === null ? null : User::fromRow($row);
    }

    public function emailExists(string $email, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE email = :email';
        $bindings = ['email' => mb_strtolower(trim($email))];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ignore_id';
            $bindings['ignore_id'] = $ignoreId;
        }

        return (int) $this->db->scalar($sql, $bindings) > 0;
    }

    /** @return list<User> */
    public function all(bool $includeDeleted = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM users';

        if (! $includeDeleted) {
            $sql .= ' WHERE deleted_at IS NULL';
        }

        $sql .= " ORDER BY FIELD(role, 'SUPER_ADMIN', 'ADMIN'), name ASC";

        return array_map(User::fromRow(...), $this->db->select($sql));
    }

    /** @return Paginator<User> */
    public function paginate(int $page, int $perPage = 20, string $basePath = ''): Paginator
    {
        return $this->paginateQuery(
            'SELECT ' . self::COLUMNS . " FROM users WHERE deleted_at IS NULL
             ORDER BY FIELD(role, 'SUPER_ADMIN', 'ADMIN'), name ASC",
            'SELECT COUNT(*) FROM users WHERE deleted_at IS NULL',
            [],
            $page,
            $perPage,
            User::fromRow(...),
            $basePath,
        );
    }

    /**
     * @param array{name: string, email: string, role: string, status?: string,
     *              password_hash?: string|null, phone?: string|null, created_by?: int|null} $data
     */
    public function create(array $data): int
    {
        $now = $this->now();

        return $this->db->insertInto('users', [
            'name' => $data['name'],
            'email' => mb_strtolower(trim($data['email'])),
            'password_hash' => $data['password_hash'] ?? null,
            'role' => $data['role'],
            'status' => $data['status'] ?? User::STATUS_PENDING,
            'phone' => $data['phone'] ?? null,
            'created_by' => $data['created_by'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('users', $id, $data) >= 0;
    }

    public function updatePassword(int $id, string $passwordHash): bool
    {
        $now = $this->now();

        return $this->db->updateWhereId('users', $id, [
            'password_hash' => $passwordHash,
            'password_changed_at' => $now,
            // Il cambio password invalida ogni altra sessione aperta.
            'sessions_valid_after' => $now,
            'updated_at' => $now,
        ]) > 0;
    }

    public function markLogin(int $id, string $ip): void
    {
        $this->db->updateWhereId('users', $id, [
            'last_login_at' => $this->now(),
            'last_login_ip' => $ip,
            'updated_at' => $this->now(),
        ]);
    }

    public function activate(int $id): bool
    {
        return $this->update($id, ['status' => User::STATUS_ACTIVE]);
    }

    /**
     * Blocca un account e invalida immediatamente le sue sessioni attive:
     * senza `sessions_valid_after` il blocco avrebbe effetto solo al logout.
     */
    public function block(int $id): bool
    {
        return $this->update($id, [
            'status' => User::STATUS_BLOCKED,
            'sessions_valid_after' => $this->now(),
        ]);
    }

    public function unblock(int $id): bool
    {
        return $this->update($id, ['status' => User::STATUS_ACTIVE]);
    }

    public function changeRole(int $id, string $role): bool
    {
        return $this->update($id, ['role' => $role]);
    }

    /** Disattivazione definitiva: soft delete più invalidazione delle sessioni. */
    public function softDeleteUser(int $id): bool
    {
        return $this->update($id, [
            'status' => User::STATUS_BLOCKED,
            'sessions_valid_after' => $this->now(),
            'deleted_at' => $this->now(),
        ]);
    }

    /**
     * Numero di super amministratori ancora operativi.
     *
     * Serve alla salvaguardia che impedisce di rimuovere, bloccare o declassare
     * l'ultimo super amministratore, lasciando il sito senza chi può gestirlo.
     */
    public function countActiveSuperAdmins(?int $excludingId = null): int
    {
        $sql = "SELECT COUNT(*) FROM users
                WHERE role = 'SUPER_ADMIN' AND status = 'active' AND deleted_at IS NULL";
        $bindings = [];

        if ($excludingId !== null) {
            $sql .= ' AND id <> :excluding';
            $bindings['excluding'] = $excludingId;
        }

        return (int) $this->db->scalar($sql, $bindings);
    }

    public function isLastActiveSuperAdmin(int $id): bool
    {
        $user = $this->find($id);

        if ($user === null || ! $user->isSuperAdmin() || ! $user->isActive()) {
            return false;
        }

        return $this->countActiveSuperAdmins($id) === 0;
    }

    /** @return array{total: int, active: int, blocked: int, pending: int} */
    public function statistics(): array
    {
        $row = $this->db->selectOne(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'active') AS active,
                SUM(status = 'blocked') AS blocked,
                SUM(status = 'pending') AS pending
             FROM users WHERE deleted_at IS NULL"
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'blocked' => (int) ($row['blocked'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
        ];
    }
}
