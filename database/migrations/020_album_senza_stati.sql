-- Gli album non hanno piu uno stato.
--
-- Erano tre - bozza, pubblicato, archiviato - e servivano a una cosa sola:
-- tenere fuori dalla galleria un album creato ma ancora vuoto, in attesa che
-- qualcuno caricasse le fotografie. Ma quella non e una decisione da prendere,
-- e un fatto che il database conosce gia: un album senza fotografie non ha
-- niente da mostrare.
--
-- Al posto dello stato, quindi, la condizione vera: nella galleria compaiono
-- gli album che hanno almeno una fotografia. Nessuna casella da ricordarsi di
-- cambiare, e nessun album pubblicato per sbaglio a meta caricamento.

ALTER TABLE albums
    DROP INDEX idx_albums_status_date,
    DROP COLUMN status;

ALTER TABLE albums
    ADD KEY idx_albums_date (event_date);

-- @DOWN

ALTER TABLE albums
    DROP INDEX idx_albums_date,
    ADD COLUMN status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'published' AFTER year,
    ADD KEY idx_albums_status_date (status, event_date);
