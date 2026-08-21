<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Support\Str;
use App\Models\Event;
use App\Models\News;

/**
 * Notizie ed eventi dimostrativi.
 *
 * I testi raccontano cose che un gruppo organizzato fa davvero: trasferte,
 * tesseramento, cene sociali, iniziative solidali. Sono verosimili proprio
 * perché vanno sostituiti, non riempiti di parole senza senso: chi apre il
 * pannello capisce subito che tono usare.
 */
final class ContentSeeder extends Seeder
{
    public function name(): string
    {
        return 'Notizie ed eventi';
    }

    public function run(): int
    {
        return $this->seedNews() + $this->seedEvents();
    }

    private function seedNews(): int
    {
        if ($this->tableHasRows('news')) {
            $this->say('Notizie già presenti: salto.');

            return 0;
        }

        $authorId = $this->db->scalar("SELECT id FROM users WHERE role = 'SUPER_ADMIN' ORDER BY id LIMIT 1");
        $created = 0;

        foreach ($this->newsFixtures() as $item) {
            $this->db->insertInto('news', [
                'title' => $item['title'],
                'slug' => Str::slug($item['title']),
                'excerpt' => $item['excerpt'],
                'content' => $item['content'],
                'image_alt' => null,
                'author_id' => $authorId === null ? null : (int) $authorId,
                'status' => $item['status'],
                'published_at' => $item['status'] === News::STATUS_PUBLISHED
                    ? $this->daysFromNow(-$item['daysAgo'], '10:30')
                    : null,
                'meta_description' => $item['excerpt'],
                'views' => $item['views'],
                'created_at' => $this->daysFromNow(-$item['daysAgo'] - 1, '18:00'),
                'updated_at' => $this->daysFromNow(-$item['daysAgo'], '10:30'),
            ]);

            $created++;
        }

        $this->say(sprintf('%d notizie create.', $created));

        return $created;
    }

    /**
     * L'unica notizia del sito: quella che ne annuncia l'apertura.
     *
     * Le altre erano contenuti dimostrativi. Averne una vera e utile e meglio
     * che averne sei inventate: il gruppo aggiungera le proprie dal pannello.
     *
     * @return list<array{title: string, excerpt: string, content: string, status: string, daysAgo: int, views: int}>
     */
    private function newsFixtures(): array
    {
        return [
            [
                'title' => 'Il sito della Baraonda è online',
                'excerpt' => 'Da oggi il gruppo ha un sito: calendario, eventi, fotografie, '
                    . 'merchandising e informazioni per iscriversi, tutto in un unico posto.',
                'content' => '<p>Da oggi il gruppo ha un sito. Nasce per avere un posto solo dove trovare '
                    . 'quello che prima girava fra messaggi, storie e passaparola: quando si gioca, '
                    . 'quando ci si trova, come ci si iscrive.</p>'
                    . '<h2>Cosa ci trovi</h2>'
                    . '<ul>'
                    . '<li><strong>Il calendario</strong> con le partite della Fiorentina e gli '
                    . 'appuntamenti del gruppo, in un unico elenco.</li>'
                    . '<li><strong>Gli eventi</strong>: trasferte organizzate, riunioni, cene sociali, '
                    . 'con luogo, orario e come partecipare.</li>'
                    . '<li><strong>Le fotografie</strong> divise per album, dalle trasferte alle coreografie.</li>'
                    . '<li><strong>Il merchandising</strong>: sciarpe, magliette e materiale del gruppo, '
                    . 'con la richiesta d\'ordine direttamente dal sito.</li>'
                    . '<li><strong>Come iscriversi</strong>, con quota e riferimenti per farlo.</li>'
                    . '</ul>'
                    . '<h2>Sugli ordini</h2>'
                    . '<p>Il sito non gestisce pagamenti: l\'ordine è una richiesta, e il pagamento '
                    . 'avviene come sempre, in sede o come concordato. Non ti verranno mai chiesti '
                    . 'dati di carte di credito.</p>'
                    . '<h2>Segnalazioni</h2>'
                    . '<p>Il sito è appena partito e continuerà a crescere. Se trovi qualcosa che non '
                    . 'funziona, o se hai fotografie e materiale da aggiungere, scrivici dalla pagina '
                    . 'dei contatti.</p>',
                'status' => News::STATUS_PUBLISHED,
                'daysAgo' => 0,
                'views' => 0,
            ],
        ];
    }

    private function seedEvents(): int
    {
        if ($this->tableHasRows('events')) {
            $this->say('Eventi già presenti: salto.');

            return 0;
        }

        $categories = $this->db->pairs('SELECT slug, id FROM event_categories');
        $authorId = $this->db->scalar("SELECT id FROM users WHERE role = 'SUPER_ADMIN' ORDER BY id LIMIT 1");
        $created = 0;

        foreach ($this->eventFixtures() as $item) {
            $categoryId = $categories[$item['category']] ?? null;

            $this->db->insertInto('events', [
                'title' => $item['title'],
                'slug' => Str::slug($item['title']),
                'short_description' => $item['short'],
                'description' => $item['description'],
                'category_id' => $categoryId === null ? null : (int) $categoryId,
                'starts_at' => $this->daysFromNow($item['inDays'], $item['time']),
                'ends_at' => null,
                'location_name' => $item['location'],
                'address' => $item['address'],
                'city' => $item['city'],
                'meeting_point' => $item['meetingPoint'],
                'meeting_at' => $item['meetingTime'] === null
                    ? null
                    : $this->daysFromNow($item['inDays'], $item['meetingTime']),
                'cost' => $item['cost'],
                'cost_note' => $item['costNote'],
                'info' => $item['info'],
                'contact_info' => 'Informazioni in sede o via email.',
                'limited_seats' => $item['limitedSeats'] ? 1 : 0,
                'seats' => $item['seats'],
                'status' => Event::STATUS_PUBLISHED,
                'is_featured' => $item['inDays'] > 0 && $item['inDays'] < 12 ? 1 : 0,
                'created_by' => $authorId === null ? null : (int) $authorId,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);

            $created++;
        }

        $this->say(sprintf('%d\'eventi creati.', $created));

        return $created;
    }

    /** @return list<array<string, mixed>> */
    private function eventFixtures(): array
    {
        return [
            [
                'title' => 'Riunione mensile dei soci',
                'category' => 'riunione',
                'short' => 'Punto sulle trasferte, tesseramento e organizzazione delle prossime iniziative.',
                'description' => '<p>Appuntamento fisso del gruppo. Si parla di trasferte in programma, tesseramento, materiale e proposte dei soci.</p><p>La riunione è aperta a tutti i tesserati. Chi vuole iscriversi può venire e farlo direttamente in sede.</p>',
                'inDays' => 5,
                'time' => '21:00',
                'location' => 'Sede della Baraonda',
                'address' => null,
                'city' => 'Firenze',
                'meetingPoint' => null,
                'meetingTime' => null,
                'cost' => null,
                'costNote' => 'Ingresso libero per i soci',
                'info' => 'Chi non può partecipare può mandare una delega scritta.',
                'limitedSeats' => false,
                'seats' => null,
            ],
            [
                'title' => 'Trasferta a Bologna',
                'category' => 'trasferta',
                'short' => 'Due pullman organizzati. Ritrovo alle 12:30, partenza alle 13 in punto.',
                'description' => '<p>Trasferta organizzata con due pullman. Il prezzo comprende viaggio e biglietto del settore ospiti.</p><h2>Programma</h2><ul><li>12:30 ritrovo al piazzale</li><li>13:00 partenza</li><li>Rientro previsto in serata</li></ul><p>Portare tessera e documento di identità: senza, l\'accesso al settore ospiti non è consentito.</p>',
                'inDays' => 12,
                'time' => '13:00',
                'location' => 'Stadio Renato Dall Ara',
                'address' => 'Via Andrea Costa 174',
                'city' => 'Bologna',
                'meetingPoint' => 'Piazzale davanti alla sede',
                'meetingTime' => '12:30',
                'cost' => 45.00,
                'costNote' => 'viaggio e biglietto inclusi',
                'info' => 'Prenotazioni in sede fino a esaurimento posti.',
                'limitedSeats' => true,
                'seats' => 108,
            ],
            [
                'title' => 'Cena sociale di primavera',
                'category' => 'cena-sociale',
                'short' => 'La cena annuale del gruppo. Menu fisso, si prenota in sede entro la settimana precedente.',
                'description' => '<p>La cena sociale è uno dei momenti più partecipati dell\'anno: si mangia insieme, si premiano i soci storici e si presentano le attività della stagione.</p><p>Menu fisso, bevande incluse. I bambini sotto i dieci anni pagano metà.</p>',
                'inDays' => 26,
                'time' => '20:00',
                'location' => 'Circolo ricreativo',
                'address' => null,
                'city' => 'Firenze',
                'meetingPoint' => null,
                'meetingTime' => null,
                'cost' => 30.00,
                'costNote' => 'bevande incluse',
                'info' => 'Prenotazione obbligatoria entro il giovedì precedente.',
                'limitedSeats' => true,
                'seats' => 140,
            ],
            [
                'title' => 'Raduno prima della partita in casa',
                'category' => 'raduno',
                'short' => 'Ritrovo al circolo due ore prima del fischio d\'inizio, poi tutti insieme allo stadio.',
                'description' => '<p>Come sempre ci troviamo al circolo due ore prima del fischio d\'inizio. Si beve qualcosa insieme e si va allo stadio in corteo.</p>',
                'inDays' => 8,
                'time' => '13:00',
                'location' => 'Circolo vicino allo stadio',
                'address' => null,
                'city' => 'Firenze',
                'meetingPoint' => 'Ingresso del circolo',
                'meetingTime' => '13:00',
                'cost' => null,
                'costNote' => null,
                'info' => null,
                'limitedSeats' => false,
                'seats' => null,
            ],
            [
                'title' => 'Festa per l\'anniversario del gruppo',
                'category' => 'festa',
                'short' => 'Musica, proiezione del video storico e taglio della torta.',
                'description' => '<p>Festeggiamo l\'anniversario della fondazione con una serata aperta a soci, familiari e amici.</p><p>In programma la proiezione del video storico del gruppo e la premiazione dei soci fondatori.</p>',
                'inDays' => 45,
                'time' => '19:30',
                'location' => 'Sede della Baraonda',
                'address' => null,
                'city' => 'Firenze',
                'meetingPoint' => null,
                'meetingTime' => null,
                'cost' => null,
                'costNote' => 'Offerta libera',
                'info' => null,
                'limitedSeats' => false,
                'seats' => null,
            ],
            [
                'title' => 'Raccolta alimentare per la mensa cittadina',
                'category' => 'iniziativa',
                'short' => 'Banchetto davanti allo stadio prima della partita: portate quello che potete.',
                'description' => '<p>Quarta edizione della raccolta alimentare. Il banchetto sarà davanti all\'ingresso della curva dalle 13 fino al fischio d\'inizio.</p><p>Servono soprattutto generi a lunga conservazione: pasta, riso, legumi in scatola, olio.</p>',
                'inDays' => -18,
                'time' => '13:00',
                'location' => 'Ingresso Curva Fiesole',
                'address' => 'Viale Manfredo Fanti',
                'city' => 'Firenze',
                'meetingPoint' => null,
                'meetingTime' => null,
                'cost' => null,
                'costNote' => null,
                'info' => null,
                'limitedSeats' => false,
                'seats' => null,
            ],
        ];
    }
}
