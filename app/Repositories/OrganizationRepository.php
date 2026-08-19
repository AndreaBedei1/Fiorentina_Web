<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Support\Str;
use App\Models\OrganizationMember;
use App\Models\OrganizationRole;

/** Accesso a ruoli e persone del direttivo. */
final class OrganizationRepository extends BaseRepository
{
    protected string $table = 'organization_roles';

    // -----------------------------------------------------------------------
    //  Ruoli
    // -----------------------------------------------------------------------

    /** @return list<OrganizationRole> */
    public function roles(): array
    {
        return array_map(
            OrganizationRole::fromRow(...),
            $this->db->select(
                'SELECT r.*, COUNT(m.id) AS members_count
                 FROM organization_roles r
                 LEFT JOIN organization_members m ON m.role_id = r.id
                 GROUP BY r.id
                 ORDER BY r.sort_order ASC, r.name ASC'
            ),
        );
    }

    public function findRole(int $id): ?OrganizationRole
    {
        $row = $this->db->selectOne('SELECT * FROM organization_roles WHERE id = :id', ['id' => $id]);

        return $row === null ? null : OrganizationRole::fromRow($row);
    }

    /** @return array<int, string> */
    public function roleOptions(): array
    {
        return array_map('strval', $this->db->pairs(
            'SELECT id, name FROM organization_roles ORDER BY sort_order ASC, name ASC'
        ));
    }

    /** @param array<string, mixed> $data */
    public function createRole(array $data): int
    {
        $now = $this->now();
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? Str::slug((string) ($data['name'] ?? '')), null, 'slug', 'organization_roles');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->db->insertInto('organization_roles', $data);
    }

    /** @param array<string, mixed> $data */
    public function updateRole(int $id, array $data): bool
    {
        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $id, 'slug', 'organization_roles');
        }

        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('organization_roles', $id, $data) >= 0;
    }

    public function deleteRole(int $id): bool
    {
        return $this->db->statement('DELETE FROM organization_roles WHERE id = :id', ['id' => $id]) > 0;
    }

    // -----------------------------------------------------------------------
    //  Persone
    // -----------------------------------------------------------------------

    /**
     * Organigramma pubblico: solo le persone marcate come visibili.
     *
     * @return list<OrganizationMember>
     */
    public function visibleMembers(): array
    {
        return array_map(
            OrganizationMember::fromRow(...),
            $this->db->select(
                'SELECT m.*, r.name AS role_name
                 FROM organization_members m
                 LEFT JOIN organization_roles r ON r.id = m.role_id
                 WHERE m.is_visible = 1
                 ORDER BY COALESCE(r.sort_order, 999) ASC, m.sort_order ASC, m.full_name ASC'
            ),
        );
    }

    /** @return list<OrganizationMember> */
    public function allMembers(): array
    {
        return array_map(
            OrganizationMember::fromRow(...),
            $this->db->select(
                'SELECT m.*, r.name AS role_name
                 FROM organization_members m
                 LEFT JOIN organization_roles r ON r.id = m.role_id
                 ORDER BY COALESCE(r.sort_order, 999) ASC, m.sort_order ASC, m.full_name ASC'
            ),
        );
    }

    public function findMember(int $id): ?OrganizationMember
    {
        $row = $this->db->selectOne(
            'SELECT m.*, r.name AS role_name
             FROM organization_members m
             LEFT JOIN organization_roles r ON r.id = m.role_id
             WHERE m.id = :id',
            ['id' => $id],
        );

        return $row === null ? null : OrganizationMember::fromRow($row);
    }

    /** @param array<string, mixed> $data */
    public function createMember(array $data): int
    {
        $now = $this->now();
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->db->insertInto('organization_members', $data);
    }

    /** @param array<string, mixed> $data */
    public function updateMember(int $id, array $data): bool
    {
        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('organization_members', $id, $data) >= 0;
    }

    public function deleteMember(int $id): ?string
    {
        $row = $this->db->selectOne('SELECT photo_key FROM organization_members WHERE id = :id', ['id' => $id]);

        $this->db->statement('DELETE FROM organization_members WHERE id = :id', ['id' => $id]);

        return $row === null ? null : ($row['photo_key'] === null ? null : (string) $row['photo_key']);
    }

    public function countMembers(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM organization_members');
    }
}
