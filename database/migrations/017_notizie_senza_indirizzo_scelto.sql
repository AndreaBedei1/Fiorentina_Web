-- Chi scrive una notizia non deve inventarne l'indirizzo.
--
-- Il modulo chiedeva uno slug: "/notizie/" e poi una stringa da comporre a
-- mano, con l'avvertenza che cambiandola i link gia condivisi smettono di
-- funzionare. Una decisione tecnica chiesta a chi voleva soltanto raccontare
-- una trasferta, e per giunta una decisione che si puo sbagliare.
--
-- Adesso l'indirizzo lo fa il sito: numero della notizia piu il titolo
-- ridotto a parole staccate, per esempio /notizie/12-trasferta-a-bologna. Il
-- numero e l'unica cosa che conta per ritrovarla, quindi cambiare il titolo
-- non rompe niente: il vecchio indirizzo continua a funzionare e porta a
-- quello nuovo. La colonna slug non serve piu, e con lei il rischio che il
-- valore salvato e il titolo raccontino due storie diverse.
--
-- Spariscono anche:
--
--   * views, il contatore delle visite. Nessuno lo guardava e non cambiava
--     nulla di cio che il gruppo fa;
--   * meta_title e meta_description, i due campi "per Google". Chiedevano di
--     riscrivere titolo e riassunto in una seconda versione, e chi scrive ne
--     ha gia scritta una: adesso i motori di ricerca leggono quella.

ALTER TABLE news
    DROP INDEX uniq_news_slug,
    DROP COLUMN slug,
    DROP COLUMN views,
    DROP COLUMN meta_title,
    DROP COLUMN meta_description;

-- @DOWN

ALTER TABLE news
    ADD COLUMN slug             VARCHAR(200) NOT NULL DEFAULT '' AFTER title,
    ADD COLUMN views            INT UNSIGNED NOT NULL DEFAULT 0 AFTER published_at,
    ADD COLUMN meta_title       VARCHAR(200) NULL DEFAULT NULL AFTER views,
    ADD COLUMN meta_description VARCHAR(300) NULL DEFAULT NULL AFTER meta_title;

UPDATE news SET slug = CONCAT('notizia-', id) WHERE slug = '';

ALTER TABLE news
    ADD UNIQUE KEY uniq_news_slug (slug);
