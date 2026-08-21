-- Il catalogo si riduce a quello che il gruppo vende davvero.
--
-- L'indirizzo lo fa il sito, come per notizie, eventi e album: numero piu
-- nome, per esempio /merchandising/2-maglietta-curva-fiesole. Via la colonna
-- slug e la casella che chiedeva di comporlo a mano.
--
-- Via option_name. Serviva a dire come si chiamava la scelta offerta da un
-- articolo - "Taglia" per l'abbigliamento, "Colore" per i cappellini - ma le
-- scelte adesso sono una sola cosa: le taglie, da XS a XXL, spuntate da un
-- elenco fisso. Un articolo senza taglie spuntate, un portachiavi, si vende
-- come e.
--
-- Via is_featured e sort_order: il primo decideva quali articoli mostrare in
-- una sezione della homepage che non esiste piu, il secondo era un numero da
-- scrivere a mano per riordinare il catalogo. Il catalogo si ordina da solo,
-- dal piu recente.
--
-- Via meta_title e meta_description: c'e gia il nome del prodotto e la sua
-- descrizione breve, e sono quelle che i motori di ricerca leggono.

ALTER TABLE products
    DROP INDEX uniq_products_slug,
    DROP COLUMN slug,
    DROP COLUMN option_name,
    DROP COLUMN is_featured,
    DROP COLUMN sort_order,
    DROP COLUMN meta_title,
    DROP COLUMN meta_description;

-- Nella riga d'ordine restava una copia dello slug del prodotto, che nessuna
-- pagina ha mai stampato: serviva a ricostruire un collegamento all'articolo,
-- ma quel collegamento non lo mostra nessuno e adesso non c'e nemmeno piu uno
-- slug da copiare.
ALTER TABLE order_items
    DROP COLUMN product_slug;

-- @DOWN

ALTER TABLE order_items
    ADD COLUMN product_slug VARCHAR(200) NULL DEFAULT NULL AFTER product_name;

ALTER TABLE products
    ADD COLUMN slug             VARCHAR(200) NOT NULL DEFAULT '' AFTER name,
    ADD COLUMN option_name      VARCHAR(30) NOT NULL DEFAULT 'Taglia' AFTER price,
    ADD COLUMN is_featured      TINYINT(1) NOT NULL DEFAULT 0 AFTER option_name,
    ADD COLUMN sort_order       INT NOT NULL DEFAULT 0 AFTER is_featured,
    ADD COLUMN meta_title       VARCHAR(200) NULL DEFAULT NULL AFTER sort_order,
    ADD COLUMN meta_description VARCHAR(300) NULL DEFAULT NULL AFTER meta_title;

UPDATE products SET slug = CONCAT('prodotto-', id) WHERE slug = '';

ALTER TABLE products
    ADD UNIQUE KEY uniq_products_slug (slug);
