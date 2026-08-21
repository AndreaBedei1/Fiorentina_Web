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
        ['presidente', 'Nome Cognome', 'Presidente', 'Guida il gruppo dal 2019. Segue i rapporti con la società e con le istituzioni.', 2005, 1],
        ['vicepresidente', 'Nome Cognome', 'Vicepresidente', 'Coordina le attività quotidiane e sostituisce il presidente quando serve.', 2008, 2],
        ['responsabile-contabile', 'Nome Cognome', 'Responsabile contabile', 'Cura quote, bilancio e rendicontazione delle trasferte.', 2010, 3],
        ['responsabile-trasferte', 'Nome Cognome', 'Responsabile trasferte', 'Organizza pullman, biglietti e logistica.', 2012, 4],
        ['responsabile-merchandising', 'Nome Cognome', 'Responsabile merchandising', 'Segue produzione, magazzino e consegna del materiale.', 2015, 5],
        ['responsabile-comunicazione', 'Nome Cognome', 'Responsabile comunicazione', 'Gestisce sito, social e archivio fotografico.', 2018, 6],
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

        $roles = $this->db->pairs('SELECT slug, id FROM organization_roles');
        $created = 0;
        $now = $this->now();

        foreach (self::MEMBERS as [$roleSlug, $fullName, $roleTitle, $bio, $since, $order]) {
            $this->db->insertInto('organization_members', [
                'role_id' => isset($roles[$roleSlug]) ? (int) $roles[$roleSlug] : null,
                'full_name' => $fullName,
                'role_title' => $roleTitle,
                'bio' => $bio . ' [dati dimostrativi da sostituire]',
                'member_since' => $since,
                'sort_order' => $order,
                'is_visible' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $created++;
        }

        $this->say(sprintf('%d persone inserite nell organigramma.', $created));

        return $created;
    }
}
