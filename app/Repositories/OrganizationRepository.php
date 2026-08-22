<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\OrganizationMember;

/**
 * Le persone del direttivo.
 *
 * L'ordine e quello che si vede nel pannello e si sposta con due frecce: un
 * numero da compilare in un modulo sarebbe una domanda in piu, e per giunta
 * una a cui si risponde male - "10" e "20" per lasciarsi spazio in mezzo, e
 * dopo tre spostamenti nessuno ci capisce piu niente.
 */
final class OrganizationRepository extends BaseRepository
{
    protected string $table = 'organization_members';

    /** @return list<OrganizationMember> */
    public function allMembers(): array
    {
        return array_map(
            OrganizationMember::fromRow(...),
            $this->db->select('SELECT * FROM organization_members ORDER BY sort_order ASC, id ASC'),
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
        // Chi arriva si mette in fondo: da li lo si sposta con le frecce.
        $data['sort_order'] = 1 + (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order), 0) FROM organization_members');

        return $this->db->insertInto('organization_members', $data);
    }

    /**
     * Sposta una persona di un posto, in su o in giu.
     *
     * Scambiare due numeri non basterebbe: se due righe finissero con lo
     * stesso valore - per una migrazione, per un inserimento andato storto -
     * lo scambio non cambierebbe niente e le frecce sembrerebbero rotte. Si
     * lavora quindi sulla lista come la si vede: si trova la riga, la si
     * scambia con la vicina e si rinumera tutto da capo.
     *
     * @param 'su'|'giu' $verso
     */
    public function sposta(int $id, string $verso): bool
    {
        $ordinati = array_map('intval', $this->db->column(
            'SELECT id FROM organization_members ORDER BY sort_order ASC, id ASC'
        ));

        $posizione = array_search($id, $ordinati, true);

        if ($posizione === false) {
            return false;
        }

        $vicina = $verso === 'su' ? $posizione - 1 : $posizione + 1;

        // Chi e gia in cima non sale, chi e gia in fondo non scende.
        if ($vicina < 0 || $vicina >= count($ordinati)) {
            return false;
        }

        [$ordinati[$posizione], $ordinati[$vicina]] = [$ordinati[$vicina], $ordinati[$posizione]];

        $this->db->transaction(function () use ($ordinati): void {
            foreach ($ordinati as $indice => $identificativo) {
                $this->db->updateWhereId('organization_members', $identificativo, ['sort_order' => $indice]);
            }
        });

        return true;
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
