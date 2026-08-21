-- Tre cose che il pannello faceva e che non servono.
--
-- 1. Registrava data e indirizzo dell'ultimo accesso di ogni amministratore.
--    Nessuno li guardava, e sono comunque dati su persone: se non servono, il
--    posto giusto per tenerli e nessuno. Chi entra e quando resta scritto nel
--    registro attivita, che e la sede propria di questa informazione e ha
--    delle regole di conservazione.
--
-- 2. Le notizie avevano tre stati: bozza, pubblicata, archiviata. Il gruppo
--    scrive una notizia quando ha qualcosa da dire e la pubblica: la bozza
--    voleva dire ricordarsi di tornare a premere "pubblica", e l'archiviazione
--    era un modo indiretto di nascondere qualcosa che si puo eliminare.
--    Da adesso una notizia esiste, e quindi si vede.
--
--    published_at diventa la data della notizia e basta, e non puo piu mancare:
--    l'elenco pubblico e ordinato su quella colonna.
--
-- 3. La dashboard non ha piu una tabella da cui leggere, ma non aveva
--    nemmeno una riga qui: spariva da sola con il suo controller.

ALTER TABLE users
    DROP COLUMN last_login_at,
    DROP COLUMN last_login_ip;

UPDATE news SET published_at = created_at WHERE published_at IS NULL;

ALTER TABLE news
    DROP INDEX idx_news_status_published,
    DROP COLUMN status,
    MODIFY COLUMN published_at DATETIME NOT NULL;

-- L'elenco pubblico ordina per data decrescente: l'indice serve quella query.
ALTER TABLE news
    ADD KEY idx_news_published (published_at);

-- @DOWN

ALTER TABLE users
    ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL AFTER status,
    ADD COLUMN last_login_ip VARCHAR(45) NULL DEFAULT NULL AFTER last_login_at;

ALTER TABLE news
    DROP INDEX idx_news_published,
    ADD COLUMN status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'published' AFTER author_id,
    MODIFY COLUMN published_at DATETIME NULL DEFAULT NULL,
    ADD KEY idx_news_status_published (status, published_at);
