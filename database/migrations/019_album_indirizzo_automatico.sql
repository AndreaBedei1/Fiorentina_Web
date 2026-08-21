-- Gli album seguono notizie ed eventi: l'indirizzo lo fa il sito.
--
-- Chi crea un album deve scrivere il titolo e caricare le fotografie, non
-- comporre a mano la stringa che finira nella barra del browser. Via la
-- colonna slug: l'indirizzo diventa numero piu titolo, per esempio
-- /galleria/3-trasferta-di-milano, e cambiare il titolo non rompe i
-- collegamenti gia condivisi.
--
-- Via anche:
--
--   * sort_order, il numero che decideva l'ordine a mano. L'elenco pubblico
--     ordinava prima per quel numero e poi per data: siccome vale zero su
--     tutti gli album, ordinava di fatto per data, ma bastava che qualcuno
--     scrivesse un numero perche un album vecchio salisse in cima senza che
--     si capisse il motivo. Adesso l'ordine e uno solo, e per data;
--   * meta_description, la "descrizione per Google". C'e gia la descrizione
--     dell'album: chiederne una seconda scritta apposta per i motori di
--     ricerca vuol dire chiedere due volte la stessa cosa.

ALTER TABLE albums
    DROP INDEX uniq_albums_slug,
    DROP COLUMN slug,
    DROP COLUMN sort_order,
    DROP COLUMN meta_description;

-- @DOWN

ALTER TABLE albums
    ADD COLUMN slug             VARCHAR(200) NOT NULL DEFAULT '' AFTER title,
    ADD COLUMN sort_order       INT NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN meta_description VARCHAR(300) NULL DEFAULT NULL AFTER sort_order;

UPDATE albums SET slug = CONCAT('album-', id) WHERE slug = '';

ALTER TABLE albums
    ADD UNIQUE KEY uniq_albums_slug (slug);
