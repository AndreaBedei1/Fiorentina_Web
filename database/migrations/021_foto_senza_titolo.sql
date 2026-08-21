-- Le fotografie non hanno titolo ne didascalia.
--
-- Ogni fotografia poteva ricevere tre testi: titolo, didascalia e testo
-- alternativo. Su un album di quaranta scatti vuol dire centoventi caselle,
-- e per un gruppo che carica le foto di una trasferta l'unica che conta e la
-- terza: quella che descrive l'immagine a chi non la vede.
--
-- Titolo e didascalia non li scriveva nessuno e servivano solo come riserva
-- per il testo alternativo mancante, cioe a rimediare a un vuoto con un altro
-- vuoto. Restano il testo alternativo e, dove serve una scritta sotto
-- l'immagine ingrandita, il nome dell'album.

ALTER TABLE photos
    DROP COLUMN title,
    DROP COLUMN caption;

-- @DOWN

ALTER TABLE photos
    ADD COLUMN title   VARCHAR(200) NULL DEFAULT NULL AFTER album_id,
    ADD COLUMN caption VARCHAR(500) NULL DEFAULT NULL AFTER title;
