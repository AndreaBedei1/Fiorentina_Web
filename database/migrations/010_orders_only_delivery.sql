-- Un ordine e sempre una spedizione, e il totale non la comprende.
--
-- Prima l'ordine chiedeva di scegliere fra consegna e ritiro in sede, calcolava
-- un costo di spedizione con tariffa fissa e soglia di gratuita, e offriva un
-- campo note. Nessuna di queste cose corrisponde a come lavora il gruppo:
--
--   * il ritiro in sede non esiste, quindi la scelta era finta e l'indirizzo
--     poteva restare vuoto;
--   * la spedizione non ha una tariffa nota al momento dell'ordine e non e mai
--     gratuita: il costo viene concordato al telefono, quando si concorda anche
--     il pagamento. Scriverlo nel totale voleva dire dare un numero inventato;
--   * le note non le leggeva nessuno, perche il contatto avviene comunque.
--
-- Restano quindi il solo importo degli articoli e un indirizzo completo, che
-- ora e obbligatorio: le colonne diventano NOT NULL perche senza indirizzo un
-- ordine non e spedibile. Gli ordini gia registrati senza indirizzo (i vecchi
-- ritiri in sede) ricevono una stringa vuota: il dato non c'era e non si puo
-- inventare.

UPDATE orders SET shipping_address     = '' WHERE shipping_address IS NULL;
UPDATE orders SET shipping_postal_code = '' WHERE shipping_postal_code IS NULL;
UPDATE orders SET shipping_city        = '' WHERE shipping_city IS NULL;
UPDATE orders SET shipping_province    = '' WHERE shipping_province IS NULL;

ALTER TABLE orders
    MODIFY COLUMN shipping_address     VARCHAR(255) NOT NULL,
    MODIFY COLUMN shipping_postal_code VARCHAR(10) NOT NULL,
    MODIFY COLUMN shipping_city        VARCHAR(100) NOT NULL,
    MODIFY COLUMN shipping_province    VARCHAR(4) NOT NULL;

-- Il totale coincide con il subtotale: la spedizione si aggiunge dopo, fuori
-- dal sito. Riallineiamo gli ordini che avevano un costo calcolato.
UPDATE orders SET total = subtotal WHERE shipping_cost <> 0.00;

ALTER TABLE orders
    DROP COLUMN shipping_method,
    DROP COLUMN shipping_cost,
    DROP COLUMN notes;

-- Le impostazioni corrispondenti spariscono dal pannello.
DELETE FROM site_settings
 WHERE key_name IN ('shop_shipping_cost', 'shop_free_shipping_threshold', 'shop_pickup_enabled');

-- L'indirizzo che riceve gli ordini si sposta da "Contatti" a "Merchandising":
-- chi configura il negozio lo cerca li, non fra i recapiti del gruppo.
UPDATE site_settings
   SET group_name  = 'merchandising',
       sort_order  = 2,
       label       = 'Email del responsabile merchandising',
       description = 'Riceve la notifica di ogni nuovo ordine. Cambiando questo indirizzo cambia il destinatario, senza toccare il codice.'
 WHERE key_name = 'contact_merchandising_email';

-- @DOWN
--
-- Le righe di site_settings non vengono ricreate qui: le ricrea
-- `php scripts/seed.php` a partire da SettingsService::DEFINITIONS.

ALTER TABLE orders
    ADD COLUMN shipping_method ENUM('delivery', 'pickup') NOT NULL DEFAULT 'delivery' AFTER customer_phone,
    ADD COLUMN notes           TEXT NULL DEFAULT NULL AFTER shipping_country,
    ADD COLUMN shipping_cost   DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER subtotal;

ALTER TABLE orders
    MODIFY COLUMN shipping_address     VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN shipping_postal_code VARCHAR(10) NULL DEFAULT NULL,
    MODIFY COLUMN shipping_city        VARCHAR(100) NULL DEFAULT NULL,
    MODIFY COLUMN shipping_province    VARCHAR(4) NULL DEFAULT NULL;

UPDATE site_settings
   SET group_name  = 'contatti',
       sort_order  = 2,
       label       = 'Email merchandising',
       description = 'Riceve la notifica di ogni nuovo ordine.'
 WHERE key_name = 'contact_merchandising_email';
