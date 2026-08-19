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
 * perche vanno sostituiti, non riempiti di parole senza senso: chi apre il
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
            $this->say('Notizie gia presenti: salto.');

            return 0;
        }

        $authorId = $this->db->scalar("SELECT id FROM users WHERE role = 'SUPER_ADMIN' ORDER BY id LIMIT 1");
        $created = 0;

        foreach ($this->newsFixtures() as $index => $item) {
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
                'is_featured' => $index === 0 ? 1 : 0,
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

    /** @return list<array{title: string, excerpt: string, content: string, status: string, daysAgo: int, views: int}> */
    private function newsFixtures(): array
    {
        return [
            [
                'title' => 'Tesseramento 2026: aperte le iscrizioni',
                'excerpt' => 'Da questa settimana e possibile rinnovare la tessera o iscriversi per la prima volta. Sede aperta il martedi e il giovedi sera.',
                'content' => '<p>Sono ufficialmente aperte le iscrizioni per la stagione 2026. Chi era gia socio puo rinnovare la tessera direttamente in sede; per le nuove iscrizioni serve un documento di identita e una fotografia formato tessera.</p>'
                    . '<h2>Cosa comprende la tessera</h2>'
                    . '<ul><li>Priorita nella prenotazione dei posti in trasferta</li>'
                    . '<li>Prezzo riservato sul materiale ufficiale</li>'
                    . '<li>Accesso alle iniziative riservate ai soci</li>'
                    . '<li>Partecipazione all assemblea annuale</li></ul>'
                    . '<h2>Quando venire</h2>'
                    . '<p>La sede e aperta il martedi e il giovedi dalle 21 alle 23. Nelle settimane con partita in casa apriamo anche il sabato pomeriggio.</p>'
                    . '<p><em>Testo dimostrativo: sostituire con le informazioni reali del gruppo.</em></p>',
                'status' => News::STATUS_PUBLISHED,
                'daysAgo' => 3,
                'views' => 214,
            ],
            [
                'title' => 'Trasferta di Bologna: due pullman al completo',
                'excerpt' => 'Posti esauriti in quarantotto ore. Ritrovo alle 12:30 al piazzale, partenza alle 13 in punto.',
                'content' => '<p>La risposta e stata immediata: i due pullman per Bologna sono al completo in meno di due giorni. Chi e rimasto fuori puo lasciare il nominativo in sede, stiamo valutando un terzo mezzo.</p>'
                    . '<h2>Informazioni pratiche</h2>'
                    . '<p>Ritrovo alle 12:30 al piazzale, partenza alle 13 in punto. Chi arriva in ritardo non viene aspettato: i tempi con la prefettura sono rigidi.</p>'
                    . '<p>Portare tessera e documento. Il biglietto viene consegnato in pullman.</p>'
                    . '<p><em>Testo dimostrativo: sostituire con le informazioni reali della trasferta.</em></p>',
                'status' => News::STATUS_PUBLISHED,
                'daysAgo' => 9,
                'views' => 178,
            ],
            [
                'title' => 'La coreografia del derby, un anno di lavoro',
                'excerpt' => 'Tremila cartoncini, quattrocento metri di teli e sei mesi di preparazione. Il racconto di come nasce una coreografia.',
                'content' => '<p>Dietro i novanta secondi in cui la curva si accende ci sono mesi di lavoro. Abbiamo raccontato tutto il percorso: dal primo bozzetto alla notte prima della partita.</p>'
                    . '<h2>I numeri</h2>'
                    . '<ul><li>Tremila cartoncini tagliati a mano</li>'
                    . '<li>Quattrocento metri di teli dipinti</li>'
                    . '<li>Ottanta persone coinvolte nel montaggio</li></ul>'
                    . '<blockquote><p>Non si fa per la fotografia. Si fa perche quando la squadra entra deve sentire che non e sola.</p></blockquote>'
                    . '<p><em>Testo dimostrativo: sostituire con il racconto reale.</em></p>',
                'status' => News::STATUS_PUBLISHED,
                'daysAgo' => 21,
                'views' => 402,
            ],
            [
                'title' => 'Raccolta alimentare: consegnati 800 chili',
                'excerpt' => 'Il risultato della raccolta di dicembre e stato consegnato alla mensa cittadina. Grazie a tutti quelli che hanno portato qualcosa.',
                'content' => '<p>La raccolta alimentare organizzata prima della gara casalinga di dicembre ha permesso di consegnare oltre ottocento chili di generi alimentari alla mensa cittadina.</p>'
                    . '<p>E la quarta edizione dell iniziativa. Ogni anno cresce, e ogni anno ci ricorda che una curva puo essere anche questo.</p>'
                    . '<p><em>Testo dimostrativo: sostituire con i dati reali dell iniziativa.</em></p>',
                'status' => News::STATUS_PUBLISHED,
                'daysAgo' => 34,
                'views' => 156,
            ],
            [
                'title' => 'Nuovo materiale disponibile in sede',
                'excerpt' => 'Sciarpe della nuova serie, felpe invernali e la maglietta celebrativa dell anniversario.',
                'content' => '<p>E arrivato il nuovo materiale. Trovate tutto nel catalogo del sito, oppure direttamente in sede negli orari di apertura.</p>'
                    . '<p>Per i soci resta attivo lo sconto riservato: ricordate di presentare la tessera.</p>'
                    . '<p><em>Testo dimostrativo: sostituire con le novita reali.</em></p>',
                'status' => News::STATUS_PUBLISHED,
                'daysAgo' => 48,
                'views' => 97,
            ],
            [
                'title' => 'Bozza: assemblea annuale dei soci',
                'excerpt' => 'Convocazione dell assemblea ordinaria. Data e ordine del giorno da definire.',
                'content' => '<p>Questa notizia e in bozza: serve a mostrare come appare un contenuto non ancora pubblicato nel pannello di gestione.</p>'
                    . '<p>Una bozza non e visibile sul sito pubblico finche non viene pubblicata.</p>',
                'status' => News::STATUS_DRAFT,
                'daysAgo' => 1,
                'views' => 0,
            ],
        ];
    }

    private function seedEvents(): int
    {
        if ($this->tableHasRows('events')) {
            $this->say('Eventi gia presenti: salto.');

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

        $this->say(sprintf('%d eventi creati.', $created));

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
                'description' => '<p>Appuntamento fisso del gruppo. Si parla di trasferte in programma, tesseramento, materiale e proposte dei soci.</p><p>La riunione e aperta a tutti i tesserati. Chi vuole iscriversi puo venire e farlo direttamente in sede.</p><p><em>Testo dimostrativo.</em></p>',
                'inDays' => 5,
                'time' => '21:00',
                'location' => 'Sede della Baraonda',
                'address' => 'DA COMPLETARE - indirizzo della sede',
                'city' => 'Firenze',
                'meetingPoint' => null,
                'meetingTime' => null,
                'cost' => null,
                'costNote' => 'Ingresso libero per i soci',
                'info' => 'Chi non puo partecipare puo mandare una delega scritta.',
                'limitedSeats' => false,
                'seats' => null,
            ],
            [
                'title' => 'Trasferta a Bologna',
                'category' => 'trasferta',
                'short' => 'Due pullman organizzati. Ritrovo alle 12:30, partenza alle 13 in punto.',
                'description' => '<p>Trasferta organizzata con due pullman. Il prezzo comprende viaggio e biglietto del settore ospiti.</p><h2>Programma</h2><ul><li>12:30 ritrovo al piazzale</li><li>13:00 partenza</li><li>Rientro previsto in serata</li></ul><p>Portare tessera e documento di identita: senza, l accesso al settore ospiti non e consentito.</p><p><em>Testo dimostrativo.</em></p>',
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
                'description' => '<p>La cena sociale e uno dei momenti piu partecipati dell anno: si mangia insieme, si premiano i soci storici e si presentano le attivita della stagione.</p><p>Menu fisso, bevande incluse. I bambini sotto i dieci anni pagano meta.</p><p><em>Testo dimostrativo.</em></p>',
                'inDays' => 26,
                'time' => '20:00',
                'location' => 'Circolo ricreativo',
                'address' => 'DA COMPLETARE - indirizzo del locale',
                'city' => 'Firenze',
                'meetingPoint' => null,
                'meetingTime' => null,
                'cost' => 30.00,
                'costNote' => 'bevande incluse',
                'info' => 'Prenotazione obbligatoria entro il giovedi precedente.',
                'limitedSeats' => true,
                'seats' => 140,
            ],
            [
                'title' => 'Raduno prima della partita in casa',
                'category' => 'raduno',
                'short' => 'Ritrovo al circolo due ore prima del fischio d inizio, poi tutti insieme allo stadio.',
                'description' => '<p>Come sempre ci troviamo al circolo due ore prima del fischio d inizio. Si beve qualcosa insieme e si va allo stadio in corteo.</p><p><em>Testo dimostrativo.</em></p>',
                'inDays' => 8,
                'time' => '13:00',
                'location' => 'Circolo vicino allo stadio',
                'address' => 'DA COMPLETARE',
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
                'title' => 'Festa per l anniversario del gruppo',
                'category' => 'festa',
                'short' => 'Musica, proiezione del video storico e taglio della torta.',
                'description' => '<p>Festeggiamo l anniversario della fondazione con una serata aperta a soci, familiari e amici.</p><p>In programma la proiezione del video storico del gruppo e la premiazione dei soci fondatori.</p><p><em>Testo dimostrativo.</em></p>',
                'inDays' => 45,
                'time' => '19:30',
                'location' => 'Sede della Baraonda',
                'address' => 'DA COMPLETARE - indirizzo della sede',
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
                'description' => '<p>Quarta edizione della raccolta alimentare. Il banchetto sara davanti all ingresso della curva dalle 13 fino al fischio d inizio.</p><p>Servono soprattutto generi a lunga conservazione: pasta, riso, legumi in scatola, olio.</p><p><em>Testo dimostrativo.</em></p>',
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
