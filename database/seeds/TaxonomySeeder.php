<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Support\Str;

/** Categorie di eventi e prodotti, ruoli del direttivo. */
final class TaxonomySeeder extends Seeder
{
    private const EVENT_CATEGORIES = [
        ['Trasferta', 'bus', 'rosso', 'Viaggi organizzati per seguire la squadra fuori casa.', 1],
        ['Riunione', 'users', 'viola', 'Incontri periodici dei soci in sede.', 2],
        ['Cena sociale', 'dinner', 'ambra', 'Cene e momenti conviviali del gruppo.', 3],
        ['Raduno', 'flag', 'verde', 'Ritrovi prima delle partite casalinghe.', 4],
        ['Festa', 'party', 'blu', 'Feste, anniversari e ricorrenze del gruppo.', 5],
        ['Iniziativa', 'star', 'sabbia', 'Iniziative solidali e attività speciali.', 6],
    ];

    private const PRODUCT_CATEGORIES = [
        ['Sciarpe', 'Le sciarpe ufficiali del gruppo, da portare in curva e in trasferta.', 1],
        ['Magliette', 'T-shirt e polo con i colori e i simboli della Baraonda.', 2],
        ['Felpe', 'Felpe e giacche per le trasferte invernali.', 3],
        ['Cappellini', 'Cappellini e berretti.', 4],
        ['Gadget', 'Adesivi, spille, portachiavi e piccoli oggetti da collezione.', 5],
    ];

    private const ROLES = [
        ['Presidente', 'Rappresenta il gruppo e coordina il direttivo.', 1],
        ['Vicepresidente', 'Affianca il presidente e ne fa le veci quando serve.', 2],
        ['Responsabile contabile', 'Cura la contabilita, le quote associative e il bilancio.', 3],
        ['Responsabile trasferte', 'Organizza pullman, biglietti e logistica delle trasferte.', 4],
        ['Responsabile merchandising', 'Segue produzione, magazzino e ordini del materiale.', 5],
        ['Responsabile comunicazione', 'Gestisce sito, social e rapporti con la stampa.', 6],
        ['Consigliere', 'Membro del direttivo.', 7],
    ];

    public function name(): string
    {
        return 'Categorie e ruoli';
    }

    public function run(): int
    {
        $created = 0;
        $now = $this->now();

        foreach (self::EVENT_CATEGORIES as [$name, $icon, $color, $description, $order]) {
            $slug = Str::slug($name);

            if ($this->tableHasRows('event_categories', 'slug = ?', [$slug])) {
                continue;
            }

            $this->db->insertInto('event_categories', [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'icon' => $icon,
                'color' => $color,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $created++;
        }

        foreach (self::PRODUCT_CATEGORIES as [$name, $description, $order]) {
            $slug = Str::slug($name);

            if ($this->tableHasRows('product_categories', 'slug = ?', [$slug])) {
                continue;
            }

            $this->db->insertInto('product_categories', [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'sort_order' => $order,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $created++;
        }

        foreach (self::ROLES as [$name, $description, $order]) {
            $slug = Str::slug($name);

            if ($this->tableHasRows('organization_roles', 'slug = ?', [$slug])) {
                continue;
            }

            $this->db->insertInto('organization_roles', [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $created++;
        }

        $this->say(sprintf('%d voci di tassonomia create.', $created));

        return $created;
    }
}
