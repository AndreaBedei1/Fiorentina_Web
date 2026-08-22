<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\OrganizationMember;

/**
 * Le persone del direttivo.
 *
 * Compaiono nell'ordine in cui sono state inserite: chi scrive l'organigramma
 * comincia dal presidente e scende, e quell'ordine e gia quello giusto. Un
 * campo "ordinamento" sarebbe una domanda in piu senza una risposta ovvia.
 */
final class OrganizationRepository extends BaseRepository
{
    protected string $table = 'organization_members';

    /** @return list<OrganizationMember> */
    public function allMembers(): array
    {
        return array_map(
            OrganizationMember::fromRow(...),
            $this->db->select('SELECT * FROM organization_members ORDER BY id ASC'),
        );
    }

    public function findMember(int $id): ?OrganizationMember
    {
        $row = $this->db->selectOne('SELECT * FROM organization_members WHERE id = :id', ['id' => $id]);

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

    /** Elimina la persona e restituisce la chiave della fotografia da togliere. */
    public function deleteMember(int $id): ?string
    {
        $row = $this->db->selectOne('SELECT photo_key FROM organization_members WHERE id = :id', ['id' => $id]);

        $this->db->statement('DELETE FROM organization_members WHERE id = :id', ['id' => $id]);

        if ($row === null || $row['photo_key'] === null) {
            return null;
        }

        return (string) $row['photo_key'];
    }
}
