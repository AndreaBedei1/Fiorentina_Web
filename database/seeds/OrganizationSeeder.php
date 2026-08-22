<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Organigramma dimostrativo.
 *
 * I nomi sono volutamente generici e riconoscibili come segnaposto: inserire
 * nomi di fantasia realistici darebbe l'impressione di persone vere, e il
 * gruppo rischierebbe di pubblicarli per distrazione.
 */
final class OrganizationSeeder extends Seeder
{
    private const MEMBERS = [
        ['Nome', 'Cognome', 'Presidente'],
        ['Nome', 'Cognome', 'Vicepresidente'],
        ['Nome', 'Cognome', 'Responsabile contabile'],
        ['Nome', 'Cognome', 'Responsabile trasferte'],
        ['Nome', 'Cognome', 'Responsabile merchandising'],
        ['Nome', 'Cognome', 'Responsabile comunicazione'],
    ];

    public function name(): string
    {
        return 'Organigramma';
    }

    public function run(): int
    {
        if ($this->tableHasRows('organization_members')) {
            $this->say('Organigramma già popolato: salto.');

            return 0;
        }

        $created = 0;
        $now = $this->now();

        // L'ordine di inserimento e quello in cui compaiono in "Chi siamo".
        foreach (self::MEMBERS as [$firstName, $lastName, $role]) {
            $this->db->insertInto('organization_members', [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $role,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $created++;
        }

        $this->say(sprintf('%d persone inserite nell organigramma.', $created));

        return $created;
    }
}
