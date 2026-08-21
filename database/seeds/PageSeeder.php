<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Page;
use App\Models\PageBlock;

/**
 * Pagine editoriali con i rispettivi blocchi.
 *
 * Sono pagine "di sistema": hanno una rotta dedicata e non si eliminano, ma
 * ogni parola e modificabile dal pannello. I testi legali contengono segnaposto
 * espliciti: scrivere una privacy policy definitiva senza conoscere i dati
 * dell'associazione sarebbe scorretto, e potenzialmente dannoso.
 */
final class PageSeeder extends Seeder
{
    public function name(): string
    {
        return 'Pagine editoriali';
    }

    public function run(): int
    {
        $created = 0;

        foreach ($this->pages() as $page) {
            if ($this->tableHasRows('pages', 'slug = ?', [$page['slug']])) {
                continue;
            }

            $now = $this->now();

            $pageId = $this->db->insertInto('pages', [
                'slug' => $page['slug'],
                'title' => $page['title'],
                'subtitle' => $page['subtitle'],
                'intro' => $page['intro'],
                'content' => $page['content'],
                'meta_title' => $page['title'],
                'meta_description' => $page['subtitle'],
                'status' => 'published',
                'is_system' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $order = 1;

            foreach ($page['blocks'] as $block) {
                $this->db->insertInto('page_blocks', [
                    'page_id' => $pageId,
                    'type' => $block['type'],
                    'title' => $block['title'] ?? null,
                    'subtitle' => $block['subtitle'] ?? null,
                    'body' => $block['body'] ?? null,
                    'items' => isset($block['items'])
                        ? json_encode($block['items'], JSON_UNESCAPED_UNICODE)
                        : null,
                    'icon' => $block['icon'] ?? null,
                    'link_url' => $block['link_url'] ?? null,
                    'link_label' => $block['link_label'] ?? null,
                    'sort_order' => $order++,
                    'is_visible' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $created++;
        }

        $this->say(sprintf('%d pagine create.', $created));

        return $created;
    }

    /** @return list<array<string, mixed>> */
    private function pages(): array
    {
        return [
            [
                'slug' => Page::SLUG_CHI_SIAMO,
                'title' => 'Chi siamo',
                'subtitle' => 'Un gruppo di tifosi nato in curva e cresciuto in trasferta.',
                'intro' => 'Siamo un gruppo organizzato di tifosi della Fiorentina. Non siamo un club esclusivo: siamo persone che hanno scelto di seguire la squadra insieme, dentro e fuori dallo stadio.',
                'content' => '<h2>Come è nato il gruppo</h2>'
                    . '<p>La Baraonda nasce da un gruppo di amici che seguiva la squadra in trasferta con mezzi propri. Con il tempo il gruppo è cresciuto, sono arrivati i primi pullman organizzati, poi la sede, poi le coreografie.</p>'
                    . ''
                    . '<h2>Cosa facciamo</h2>'
                    . '<p>Organizziamo trasferte, curiamo le coreografie della curva, teniamo viva la sede come punto di ritrovo, promuoviamo iniziative solidali sul territorio.</p>'
                    . '<h2>Il rapporto con Firenze</h2>'
                    . '<p>Il legame con la città non finisce con la partita: partecipiamo a iniziative di quartiere, raccolte alimentari e attività con le scuole.</p>'
                    . '',
                'blocks' => [
                    [
                        'type' => PageBlock::TYPE_TIMELINE,
                        'title' => 'Le tappe principali',
                                                'items' => [
                            ['title' => '2015', 'text' => 'Nasce il gruppo, prime trasferte con mezzi propri.'],
                            ['title' => '2018', 'text' => 'Apre la prima sede.'],
                            ['title' => '2021', 'text' => 'Prima grande coreografia della curva.'],
                            ['title' => '2023', 'text' => "Nasce l'attività solidale sul territorio."],
                            ['title' => 'Oggi', 'text' => 'Oltre quattrocento soci e una trasferta quasi ogni settimana.'],
                        ],
                    ],
                    [
                        'type' => PageBlock::TYPE_LIST,
                        'title' => 'I nostri valori',
                        'items' => [
                            ['title' => 'Appartenenza', 'text' => 'Si entra per restare, non per una stagione.'],
                            ['title' => 'Rispetto', 'text' => "Fra noi, verso la città e verso chi tifa un'altra squadra."],
                            ['title' => 'Indipendenza', 'text' => 'Il gruppo si autofinanzia e decide da sé.'],
                            ['title' => 'Solidarietà', 'text' => 'Quello che possiamo fare per il territorio, lo facciamo.'],
                        ],
                    ],
                    [
                        'type' => PageBlock::TYPE_STATS,
                        'title' => 'Il gruppo in numeri',
                                                'items' => [
                            ['title' => 'Anno di fondazione', 'text' => '2015'],
                            ['title' => 'Soci tesserati', 'text' => 'oltre 400'],
                            ['title' => "Trasferte all'anno", 'text' => 'circa 20'],
                            ['title' => 'Coreografie realizzate', 'text' => 'più di 60'],
                        ],
                    ],
                    // Nessun invito all'iscrizione qui: la pagina si chiude
                    // gia con quello sotto l'organigramma, e ripeterlo a meta
                    // pagina lo indeboliva soltanto.
                ],
            ],
            [
                'slug' => Page::SLUG_DIVENTA_SOCIO,
                'title' => 'Diventa socio',
                'subtitle' => 'Entrare nella Baraonda richiede pochi minuti. Ecco tutto quello che serve sapere.',
                'intro' => 'La tessera non è un pezzo di plastica: è il modo con cui il gruppo si sostiene e si organizza. Chi si tessera ha priorità sulle trasferte, prezzi riservati sul materiale e voce in assemblea.',
                'content' => null,
                'blocks' => [
                    [
                        'type' => PageBlock::TYPE_LIST,
                        'title' => 'Perché iscriversi',
                        'items' => [
                            ['title' => 'Priorità sulle trasferte', 'text' => 'I posti sui pullman vengono assegnati prima ai tesserati.'],
                            ['title' => 'Prezzo riservato sul materiale', 'text' => 'Sconto dedicato su sciarpe, magliette e felpe.'],
                            ['title' => 'Iniziative riservate', 'text' => 'Cene, feste e incontri aperti ai soli soci.'],
                            ['title' => 'Voce in assemblea', 'text' => 'Ogni socio vota le scelte del gruppo.'],
                        ],
                    ],
                    [
                        'type' => PageBlock::TYPE_STEPS,
                        'title' => 'Come iscriversi',
                        'subtitle' => 'Tre passaggi, si fa tutto in sede.',
                        'items' => [
                            ['title' => 'Vieni in sede', 'text' => 'Negli orari di apertura, senza appuntamento.'],
                            ['title' => 'Porta i documenti', 'text' => 'Documento di identità valido e una fotografia formato tessera.'],
                            ['title' => 'Versa la quota', 'text' => 'Ricevi subito la tessera e sei dei nostri.'],
                        ],
                    ],
                    [
                        'type' => PageBlock::TYPE_HIGHLIGHT,
                        'title' => 'Quota associativa',
                        'body' => "<p>Per conoscere l'importo della quota annuale e le modalità di pagamento, scrivici: ti rispondiamo in giornata.</p>",
                        'icon' => 'star',
                    ],
                    [
                        'type' => PageBlock::TYPE_FAQ,
                        'title' => 'Domande frequenti',
                        'items' => [
                            ['title' => 'Devo essere maggiorenne?', 'text' => "I minori possono iscriversi con l'autorizzazione di un genitore."],
                            ['title' => 'Posso iscrivermi se non vivo a Firenze?', 'text' => 'Certo. Molti soci arrivano da fuori città e raggiungono i pullman lungo il percorso.'],
                            ['title' => 'La tessera dura un anno?', 'text' => 'Sì, segue la stagione sportiva e si rinnova ogni anno.'],
                            ['title' => 'Devo partecipare a tutte le attività?', 'text' => 'No. Ognuno partecipa a quello che può e che vuole.'],
                        ],
                    ],
                    [
                        'type' => PageBlock::TYPE_CTA,
                        'title' => 'Hai ancora dubbi?',
                        'body' => '<p>Scrivici: rispondiamo a tutti, di solito entro qualche giorno.</p>',
                        'link_url' => '/contatti',
                        'link_label' => 'Contattaci',
                    ],
                ],
            ],
            [
                'slug' => Page::SLUG_CONTATTI,
                'title' => 'Contatti',
                'subtitle' => 'Scrivici per informazioni su iscrizioni, trasferte, materiale ed eventi.',
                'intro' => 'Scrivici con il modulo qui sotto, per email o dai nostri profili social: rispondiamo appena possibile.',
                'content' => null,
                'blocks' => [],
            ],
            [
                'slug' => Page::SLUG_PRIVACY,
                'title' => 'Informativa sulla privacy',
                'subtitle' => 'Come trattiamo i dati personali di chi usa questo sito.',
                'intro' => null,
                'content' => '<p><strong>DA COMPLETARE.</strong> Questo testo è uno schema da far verificare a chi di competenza prima della pubblicazione: una informativa privacy inventata sarebbe inutile e potenzialmente dannosa.</p>'
                    . '<h2>Titolare del trattamento</h2>'
                    . '<p>DA COMPLETARE: denominazione completa dell associazione, sede legale, codice fiscale e indirizzo email di contatto.</p>'
                    . '<h2>Quali dati raccogliamo</h2>'
                    . '<p>Il sito raccoglie dati personali solo in due casi, entrambi su iniziativa dell utente:</p>'
                    . '<ul>'
                    . '<li><strong>Modulo contatti</strong>: nome, indirizzo email, oggetto e testo del messaggio.</li>'
                    . '<li><strong>Richiesta di ordine del materiale</strong>: nome, cognome, email, telefono e, se richiesta la spedizione, indirizzo completo.</li>'
                    . '</ul>'
                    . '<p>Il sito non raccoglie dati di pagamento di alcun tipo: il pagamento avviene sempre al di fuori del sito.</p>'
                    . '<h2>Finalità e base giuridica</h2>'
                    . '<p>I dati vengono usati esclusivamente per rispondere alle richieste e per gestire la consegna del materiale ordinato. DA COMPLETARE con la base giuridica applicabile.</p>'
                    . '<h2>Conservazione</h2>'
                    . '<p>DA COMPLETARE: indicare per quanto tempo vengono conservati messaggi e ordini.</p>'
                    . '<h2>Diritti dell interessato</h2>'
                    . '<p>Ogni persona può chiedere accesso, rettifica o cancellazione dei propri dati scrivendo all indirizzo email del titolare.</p>'
                    . '<h2>Servizi di terze parti</h2>'
                    . '<p>Il sito non utilizza servizi di analisi statistica né strumenti pubblicitari. Le anteprime dei contenuti social sono copiate sul nostro server: consultando il sito non vengono contattati i server di terze parti.</p>',
                'blocks' => [],
            ],
            [
                'slug' => Page::SLUG_COOKIE,
                'title' => 'Cookie policy',
                'subtitle' => 'Quali cookie usa questo sito e a cosa servono.',
                'intro' => null,
                'content' => '<p><strong>DA COMPLETARE.</strong> Far verificare il testo prima della pubblicazione.</p>'
                    . '<h2>Cookie utilizzati</h2>'
                    . '<p>Il sito utilizza esclusivamente cookie tecnici, necessari al funzionamento:</p>'
                    . '<ul>'
                    . '<li><strong>Cookie di sessione</strong>: mantiene il contenuto del carrello e protegge i moduli dagli invii fraudolenti. Si cancella alla chiusura del browser.</li>'
                    . '</ul>'
                    . '<p>Non vengono utilizzati cookie di profilazione, di analisi statistica o di terze parti. Per questo motivo non è presente alcun banner di consenso: i cookie tecnici non lo richiedono.</p>'
                    . '<h2>Se in futuro cambiassero le cose</h2>'
                    . '<p>Se venissero introdotti strumenti che richiedono il consenso (per esempio statistiche o mappe incorporate), questa pagina verrà aggiornata e comparirà il relativo banner.</p>'
                    . '<h2>Come disattivare i cookie</h2>'
                    . '<p>Ogni browser permette di bloccare i cookie dalle proprie impostazioni. Disattivando i cookie tecnici, carrello e moduli del sito smettono di funzionare correttamente.</p>',
                'blocks' => [],
            ],
        ];
    }
}
