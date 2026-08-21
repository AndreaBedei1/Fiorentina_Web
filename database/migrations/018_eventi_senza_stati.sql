-- Gli eventi seguono le notizie: si scrivono e si pubblicano.
--
-- Sparisce lo stato. Erano quattro - bozza, pubblicato, annullato,
-- archiviato - e per un gruppo che mette in calendario una trasferta quando
-- decide di organizzarla erano tre passaggi in piu fra il decidere e il farlo
-- sapere. Da adesso un evento in calendario e un evento che si vede.
--
-- Attenzione a una conseguenza: "annullato" non c'e piu. Una trasferta che
-- salta si racconta nella descrizione oppure si elimina, ma il sito non ha
-- piu un modo di dire "questo appuntamento non si fa" tenendolo visibile.
--
-- Sparisce anche "metti in evidenza": la casella c'era, si poteva spuntare, e
-- nessuna pagina del sito guardava quel valore.
--
-- Lo slug segue la strada delle notizie: l'indirizzo lo fa il sito, numero
-- piu titolo, e cambiare il titolo non rompe i collegamenti gia condivisi.
--
-- E via il link alla mappa: un campo in piu da incollare per ogni evento,
-- quando luogo, indirizzo e citta bastano a chiunque per cercarselo.

ALTER TABLE events
    DROP INDEX uniq_events_slug,
    DROP INDEX idx_events_status_starts,
    DROP COLUMN slug,
    DROP COLUMN status,
    DROP COLUMN is_featured,
    DROP COLUMN maps_url;

-- L'elenco, pubblico e non, ordina sempre per data.
ALTER TABLE events
    ADD KEY idx_events_starts (starts_at);

-- @DOWN

ALTER TABLE events
    DROP INDEX idx_events_starts,
    ADD COLUMN slug        VARCHAR(200) NOT NULL DEFAULT '' AFTER title,
    ADD COLUMN maps_url    VARCHAR(500) NULL DEFAULT NULL AFTER meeting_point,
    ADD COLUMN status      ENUM('draft', 'published', 'cancelled', 'archived') NOT NULL DEFAULT 'published' AFTER contact_info,
    ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

UPDATE events SET slug = CONCAT('evento-', id) WHERE slug = '';

ALTER TABLE events
    ADD UNIQUE KEY uniq_events_slug (slug),
    ADD KEY idx_events_status_starts (status, starts_at);
