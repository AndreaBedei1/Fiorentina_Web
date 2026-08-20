<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;

/**
 * Impostazioni modificabili dal pannello amministrativo.
 *
 * Regola importante: qui dentro finiscono solo valori NON sensibili (indirizzi,
 * link social, testi, costi di spedizione). Chiavi API, token e credenziali
 * restano nel file .env, fuori dal database e fuori dalla portata del pannello.
 */
final class SettingsService
{
    /**
     * Definizione delle impostazioni note.
     *
     * Questa tabella e la fonte di verita: `php scripts/seed.php` la usa per
     * creare le righe mancanti, e il pannello per generare il form. Aggiungere
     * un'impostazione significa aggiungere una riga qui.
     *
     * @var array<string, array{group: string, type: string, label: string, default: string|null, description?: string, sort?: int}>
     */
    public const DEFINITIONS = [
        // --- Generale -------------------------------------------------------
        'site_group_name' => [
            'group' => 'generale', 'type' => 'string', 'sort' => 1,
            'label' => 'Nome del gruppo', 'default' => 'Baraonda Fiorentina',
        ],
        'site_tagline' => [
            'group' => 'generale', 'type' => 'string', 'sort' => 2,
            'label' => 'Motto', 'default' => 'Il cuore viola che non si ferma mai',
            'description' => 'Frase breve mostrata sotto il logo nella sezione di apertura.',
        ],
        'site_founded_year' => [
            'group' => 'generale', 'type' => 'int', 'sort' => 3,
            'label' => 'Anno di fondazione', 'default' => '2015',
        ],
        'site_description' => [
            'group' => 'generale', 'type' => 'text', 'sort' => 4,
            'label' => 'Descrizione per i motori di ricerca', 'default' => 'Baraonda Fiorentina e il gruppo organizzato di tifosi viola: trasferte, eventi, fotografie e merchandising ufficiale.',
            'description' => 'Massimo 160 caratteri: e il testo che compare nei risultati di Google.',
        ],

        // --- Contatti -------------------------------------------------------
        'contact_email' => [
            'group' => 'contatti', 'type' => 'email', 'sort' => 1,
            'label' => 'Email principale', 'default' => 'info@baraondafiorentina.it',
        ],
        'contact_merchandising_email' => [
            'group' => 'contatti', 'type' => 'email', 'sort' => 2,
            'label' => 'Email merchandising', 'default' => 'merchandising@baraondafiorentina.it',
            'description' => 'Riceve la notifica di ogni nuovo ordine.',
        ],
        'contact_phone' => [
            'group' => 'contatti', 'type' => 'string', 'sort' => 3,
            'label' => 'Telefono', 'default' => '',
        ],
        'contact_address' => [
            'group' => 'contatti', 'type' => 'text', 'sort' => 4,
            'label' => 'Sede', 'default' => '',
            'description' => 'Indirizzo della sede. Finche e vuoto, nel piede di pagina non compare nulla.',
        ],
        'contact_opening_hours' => [
            'group' => 'contatti', 'type' => 'text', 'sort' => 5,
            'label' => 'Orari di apertura sede', 'default' => '',
            'description' => 'Per esempio: martedì e giovedì dalle 21 alle 23.',
        ],

        // --- Social ---------------------------------------------------------
        'social_instagram_url' => [
            'group' => 'social', 'type' => 'url', 'sort' => 1,
            'label' => 'Profilo Instagram', 'default' => 'https://www.instagram.com/',
        ],
        'social_facebook_url' => [
            'group' => 'social', 'type' => 'url', 'sort' => 2,
            'label' => 'Pagina Facebook', 'default' => 'https://www.facebook.com/',
        ],
        'social_youtube_channel_id' => [
            'group' => 'social', 'type' => 'string', 'sort' => 6,
            'label' => 'Identificativo del canale YouTube',
            'default' => '',
            'description' => "Si compila da solo partendo dall'indirizzo del canale. Serve a leggere i video dal feed pubblico di YouTube, che non richiede alcuna chiave.",
        ],
        'social_youtube_url' => [
            'group' => 'social', 'type' => 'url', 'sort' => 3,
            'label' => 'Canale YouTube', 'default' => '',
        ],
        'social_telegram_url' => [
            'group' => 'social', 'type' => 'url', 'sort' => 4,
            'label' => 'Canale Telegram', 'default' => '',
        ],

        // --- Homepage -------------------------------------------------------
        'home_hero_title' => [
            'group' => 'homepage', 'type' => 'string', 'sort' => 1,
            'label' => 'Titolo sezione di apertura', 'default' => 'Siamo la Baraonda',
        ],
        'home_hero_subtitle' => [
            'group' => 'homepage', 'type' => 'text', 'sort' => 2,
            'label' => 'Sottotitolo sezione di apertura',
            'default' => 'Dal 2015 seguiamo la Fiorentina ovunque giochi. Trasferte, curva, amicizia: entra anche tu nella Baraonda.',
        ],
        'home_cta_label' => [
            'group' => 'homepage', 'type' => 'string', 'sort' => 3,
            'label' => 'Testo del pulsante principale', 'default' => 'Diventa socio',
        ],
        'home_show_social' => [
            'group' => 'homepage', 'type' => 'bool', 'sort' => 4,
            'label' => 'Mostra la sezione social', 'default' => '1',
        ],
        'home_social_per_platform' => [
            'group' => 'homepage', 'type' => 'int', 'sort' => 5,
            'label' => 'Contenuti social per piattaforma', 'default' => '2',
            'description' => 'Quanti contenuti mostrare per ciascun social. Con 2 si vedono gli ultimi due post di Instagram e gli ultimi due di Facebook.',
        ],
        'home_show_next_match' => [
            'group' => 'homepage', 'type' => 'bool', 'sort' => 5,
            'label' => 'Mostra la prossima partita', 'default' => '1',
        ],
        'home_show_team_logos' => [
            'group' => 'homepage', 'type' => 'bool', 'sort' => 6,
            'label' => 'Mostra gli stemmi delle squadre', 'default' => '0',
            'description' => 'Da attivare solo dopo aver verificato di poter usare gli stemmi: sono marchi registrati dei club.',
        ],

        // --- Iscrizioni -----------------------------------------------------
        'membership_fee' => [
            'group' => 'iscrizioni', 'type' => 'string', 'sort' => 1,
            'label' => 'Quota associativa annuale', 'default' => '',
            'description' => 'Per esempio: 25 euro. Finche e vuota non viene mostrato alcun importo.',
        ],
        'membership_contact' => [
            'group' => 'iscrizioni', 'type' => 'string', 'sort' => 2,
            'label' => 'Riferimento per le iscrizioni', 'default' => 'info@baraondafiorentina.it',
        ],

        // --- Merchandising --------------------------------------------------
        'shop_enabled' => [
            'group' => 'merchandising', 'type' => 'bool', 'sort' => 1,
            'label' => 'Catalogo attivo', 'default' => '1',
            'description' => 'Disattivandolo il catalogo resta visibile ma non e possibile inviare ordini.',
        ],
        'shop_shipping_cost' => [
            'group' => 'merchandising', 'type' => 'float', 'sort' => 2,
            'label' => 'Costo di spedizione', 'default' => '7.00',
        ],
        'shop_free_shipping_threshold' => [
            'group' => 'merchandising', 'type' => 'float', 'sort' => 3,
            'label' => 'Soglia per la spedizione gratuita', 'default' => '80.00',
            'description' => 'Impostare 0 per disattivare la spedizione gratuita.',
        ],
        'shop_pickup_enabled' => [
            'group' => 'merchandising', 'type' => 'bool', 'sort' => 4,
            'label' => 'Consenti il ritiro in sede', 'default' => '1',
        ],
        'shop_payment_instructions' => [
            'group' => 'merchandising', 'type' => 'text', 'sort' => 5,
            'label' => 'Istruzioni di pagamento',
            'default' => '',
            'description' => "Come pagare l'ordine: bonifico con IBAN, pagamento in sede o alla consegna. Questo testo viene inviato al cliente nella email di conferma, quindi conviene compilarlo prima di aprire il merchandising.",
        ],

        // --- Filigrana ------------------------------------------------------
        'watermark_enabled' => [
            'group' => 'immagini', 'type' => 'bool', 'sort' => 1,
            'label' => 'Applica la filigrana alle fotografie', 'default' => '1',
        ],
        'watermark_position' => [
            'group' => 'immagini', 'type' => 'string', 'sort' => 2,
            'label' => 'Posizione della filigrana', 'default' => 'bottom-right',
            'description' => 'Valori ammessi: bottom-right, bottom-left, top-right, top-left, center, tiled.',
        ],
        'watermark_opacity' => [
            'group' => 'immagini', 'type' => 'int', 'sort' => 3,
            'label' => 'Opacita della filigrana (%)', 'default' => '38',
        ],
        'watermark_scale' => [
            'group' => 'immagini', 'type' => 'int', 'sort' => 4,
            'label' => 'Dimensione della filigrana (% del lato lungo)', 'default' => '18',
        ],

        // --- Piede di pagina ------------------------------------------------
        'footer_text' => [
            'group' => 'footer', 'type' => 'text', 'sort' => 1,
            'label' => 'Testo del piede di pagina',
            'default' => 'Baraonda Fiorentina è un gruppo di tifosi indipendente. Non è affiliato né rappresenta ACF Fiorentina.',
        ],
        'footer_association_details' => [
            'group' => 'footer', 'type' => 'text', 'sort' => 2,
            'label' => 'Dati dell associazione',
            'default' => '',
            'description' => "Denominazione, sede legale e codice fiscale. Vanno indicati se il gruppo e un'associazione registrata. Finche e vuoto, nel piede di pagina non compare nulla.",
        ],
    ];

    /** @var array<string, mixed> */
    private array $resolved = [];

    public function __construct(private readonly SettingsRepository $repository)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $raw = $this->repository->allRaw()[$key] ?? null;

        if ($raw === null) {
            // Chiave non ancora presente a database: usiamo il valore di progetto.
            $definition = self::DEFINITIONS[$key] ?? null;

            if ($definition === null) {
                return $default;
            }

            return $this->resolved[$key] = $this->cast($definition['default'], $definition['type']) ?? $default;
        }

        $value = $this->cast($raw['value'], $raw['type']);

        return $this->resolved[$key] = $value ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /** @param array<string, string|null> $values */
    public function updateMany(array $values, ?int $updatedBy = null): void
    {
        $allowed = array_intersect_key($values, self::DEFINITIONS);

        $this->repository->putMany($allowed, $updatedBy);
        $this->resolved = [];
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function groupedForAdmin(): array
    {
        return $this->repository->groupedForAdmin();
    }

    /** Crea a database le impostazioni mancanti, senza toccare quelle esistenti. */
    public function ensureDefaults(): int
    {
        $created = 0;
        $existing = $this->repository->allRaw();

        foreach (self::DEFINITIONS as $key => $definition) {
            if (isset($existing[$key])) {
                continue;
            }

            $this->repository->ensure($key, $definition['default'], [
                'group' => $definition['group'],
                'type' => $definition['type'],
                'label' => $definition['label'],
                'description' => $definition['description'] ?? null,
                'sort' => $definition['sort'] ?? 0,
            ]);

            $created++;
        }

        $this->repository->forget();
        $this->resolved = [];

        return $created;
    }

    /** @return array<string, string> Etichette leggibili dei gruppi di impostazioni. */
    public static function groupLabels(): array
    {
        return [
            'generale' => 'Informazioni generali',
            'contatti' => 'Contatti',
            'social' => 'Social network',
            'homepage' => 'Homepage',
            'iscrizioni' => 'Iscrizioni',
            'merchandising' => 'Merchandising',
            'immagini' => 'Immagini e filigrana',
            'footer' => 'Piede di pagina',
        ];
    }

    private function cast(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            'json' => json_decode($value, true) ?? [],
            default => $value,
        };
    }
}
