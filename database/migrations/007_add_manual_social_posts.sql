-- Contenuti social inseriti a mano dal pannello.
--
-- Prendere i contenuti dalle API di Instagram e Facebook richiede un'app Meta,
-- un account professionale e un token che scade ogni sessanta giorni. Per un
-- gruppo che pubblica qualche post a settimana e un impegno sproporzionato:
-- basta poter incollare il link di un post, caricare l'immagine e scrivere una
-- riga di didascalia.
--
-- Da qui la colonna: le voci scritte a mano devono sopravvivere alle
-- sincronizzazioni, che altrimenti le poterebbero insieme a quelle vecchie
-- scaricate dalle API.

ALTER TABLE social_posts
    ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER is_visible;

-- Le voci gia presenti arrivano tutte dalla sincronizzazione.
UPDATE social_posts SET is_manual = 0;

-- L'indice su (is_visible, published_at) c'e gia dalla migrazione 005: e lo
-- stesso che serve qui, perche l'ordinamento in homepage non cambia.

-- @DOWN

ALTER TABLE social_posts DROP COLUMN is_manual;
