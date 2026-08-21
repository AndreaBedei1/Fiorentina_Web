-- Un prodotto a catalogo e un prodotto che si puo ordinare.
--
-- Il catalogo teneva il conto di due cose che il gruppo non tiene.
--
-- Lo stato - bozza, pubblicato, archiviato - come per notizie ed eventi: un
-- articolo si mette a catalogo quando lo si vende, non prima.
--
-- La disponibilita, in tre forme sovrapposte: la casella "Disponibilita"
-- (disponibile, esaurito, su prenotazione, non piu disponibile), la giacenza
-- del prodotto e la giacenza di ogni singola taglia. Tre modi di dire la
-- stessa cosa, e tutti e tre andavano tenuti aggiornati a mano perche il sito
-- non sa cosa c'e negli scatoloni: nessuno lo faceva, e un catalogo che
-- dichiara "esaurito" quando non lo e, o "disponibile" quando lo e, e peggio
-- di uno che non dichiara niente.
--
-- Da adesso quello che sta a catalogo si ordina, e la conferma della
-- disponibilita arriva quando il gruppo ricontatta chi ha ordinato - che e
-- comunque il momento in cui si concordano pagamento e spedizione.

ALTER TABLE products
    DROP INDEX idx_products_status_featured,
    DROP COLUMN status,
    DROP COLUMN availability,
    DROP COLUMN track_quantity,
    DROP COLUMN quantity;

ALTER TABLE product_variants
    DROP COLUMN quantity,
    DROP COLUMN is_available;

-- @DOWN

ALTER TABLE products
    ADD COLUMN status         ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'published' AFTER price,
    ADD COLUMN availability   ENUM('in_stock', 'out_of_stock', 'preorder', 'discontinued') NOT NULL DEFAULT 'in_stock' AFTER status,
    ADD COLUMN track_quantity TINYINT(1) NOT NULL DEFAULT 0 AFTER availability,
    ADD COLUMN quantity       INT NULL DEFAULT NULL AFTER track_quantity,
    ADD KEY idx_products_status_featured (status, is_featured);

ALTER TABLE product_variants
    ADD COLUMN quantity     INT NULL DEFAULT NULL AFTER label,
    ADD COLUMN is_available TINYINT(1) NOT NULL DEFAULT 1 AFTER quantity;
