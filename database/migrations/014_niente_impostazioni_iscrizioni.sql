-- La quota e il riferimento per le iscrizioni non sono piu impostazioni.
--
-- La pagina "Diventa socio" mostrava due schede fisse, "Quota associativa" e
-- "A chi rivolgersi", riempite da queste due impostazioni. Ripetevano cose che
-- la pagina diceva gia con i propri blocchi: il blocco "Quota associativa" era
-- li sopra, con lo stesso titolo.
--
-- Tolte le schede, nessuno leggeva piu i due valori. Lasciarli nel pannello
-- sarebbe stata una trappola: un amministratore scrive "25 euro" nella casella
-- della quota, salva, e sul sito non cambia niente. Quel testo si scrive
-- adesso dove viene mostrato, cioe in Pagine, "Diventa socio".
--
-- Con loro sparisce anche il gruppo "Iscrizioni", che non conteneva altro.

DELETE FROM site_settings WHERE key_name IN ('membership_fee', 'membership_contact');

-- @DOWN
--
-- Le righe vengono ricreate da `php scripts/seed.php` a partire da
-- SettingsService::DEFINITIONS.
