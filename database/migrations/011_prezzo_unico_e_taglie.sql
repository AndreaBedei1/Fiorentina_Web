-- Un prezzo solo per prodotto, e le taglie chiamate col loro nome.
--
-- Tre cose che il catalogo permetteva e che il gruppo non fa:
--
--   * far costare la XXL due euro piu della M. Il prezzo di un articolo e
--     uno solo, qualunque taglia si scelga, quindi price_modifier sparisce;
--   * mostrare sconti e percentuali. Non ci sono offerte ne promozioni: il
--     prezzo di listino barrato accanto a quello scontato prometteva un
--     ribasso che non esiste, quindi sparisce compare_at_price;
--   * chiedere all'amministratore la stessa cosa tre volte. Ogni variante
--     aveva etichetta, taglia e colore, ma il sito mostrava solo l'etichetta:
--     size e color erano copie mai lette. Stessa sorte per sku, che nessun
--     modulo compilava e nessuna pagina stampava.
--
-- Al posto loro il prodotto dice come si chiama la scelta che offre. Le
-- magliette offrono una "Taglia", i cappellini un "Colore": senza questo il
-- carrello scriveva "Variante: S", che non e il nome di niente. Il valore
-- predefinito e "Taglia" perche quasi tutto il catalogo e abbigliamento.
--
-- Le righe d'ordine gia registrate conservano l'etichetta ma non il nome della
-- scelta: quello che non e stato scritto allora non si puo ricostruire adesso,
-- e la pagina dell'ordine si limita a mostrare l'etichetta.

ALTER TABLE products
    DROP COLUMN compare_at_price,
    ADD COLUMN option_name VARCHAR(30) NOT NULL DEFAULT 'Taglia' AFTER price;

ALTER TABLE product_variants
    DROP COLUMN price_modifier,
    DROP COLUMN size,
    DROP COLUMN color,
    DROP COLUMN sku;

ALTER TABLE order_items
    ADD COLUMN variant_option VARCHAR(30) NULL DEFAULT NULL AFTER product_slug;

-- @DOWN

ALTER TABLE products
    DROP COLUMN option_name,
    ADD COLUMN compare_at_price DECIMAL(8, 2) NULL DEFAULT NULL AFTER price;

ALTER TABLE product_variants
    ADD COLUMN size           VARCHAR(40) NULL DEFAULT NULL AFTER label,
    ADD COLUMN color          VARCHAR(40) NULL DEFAULT NULL AFTER size,
    ADD COLUMN sku            VARCHAR(60) NULL DEFAULT NULL AFTER color,
    ADD COLUMN price_modifier DECIMAL(8, 2) NOT NULL DEFAULT 0.00 AFTER sku;

ALTER TABLE order_items
    DROP COLUMN variant_option;
