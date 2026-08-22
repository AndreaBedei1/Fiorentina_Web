-- Un ordine e da gestire, oppure e completato. Non c'e altro.
--
-- Gli stati erano otto: nuovo, cliente contattato, in attesa di pagamento,
-- pagato, in preparazione, spedito, completato, annullato. Descrivevano il
-- lavoro di un negozio con un magazzino e un corriere. Qui il lavoro e un
-- altro: arriva una richiesta, qualcuno telefona, ci si accorda, e quando la
-- roba e nelle mani della persona l'ordine e chiuso. Le sette caselle in
-- mezzo nessuno le avrebbe aggiornate, e uno stato che nessuno aggiorna dice
-- il falso: "in preparazione" su un ordine consegnato tre settimane fa e
-- peggio di nessuno stato.
--
-- Restano i due che si distinguono da soli guardando la scrivania: c'e ancora
-- da fare qualcosa, oppure no.
--
-- Con gli stati se ne va lo storico dei passaggi, che senza passaggi non ha
-- piu niente da registrare, e le note interne, che erano un campo di testo
-- libero accanto a un numero di telefono: chi segue un ordine lo segue al
-- telefono, non scrivendo memorandum in un pannello.

UPDATE orders SET status = 'NEW' WHERE status <> 'COMPLETED';

ALTER TABLE orders MODIFY COLUMN status ENUM('NEW', 'COMPLETED') NOT NULL DEFAULT 'NEW';
ALTER TABLE orders DROP COLUMN admin_notes;

DROP TABLE order_status_history;

-- @DOWN

CREATE TABLE order_status_history (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id    INT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NULL DEFAULT NULL,
    to_status   VARCHAR(20) NOT NULL,
    note        VARCHAR(255) NULL DEFAULT NULL,
    changed_by  INT UNSIGNED NULL DEFAULT NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_order_status_history_order (order_id, created_at),
    CONSTRAINT fk_order_history_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_history_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE orders ADD COLUMN admin_notes TEXT NULL DEFAULT NULL AFTER shipping_country;

ALTER TABLE orders MODIFY COLUMN status
    ENUM('NEW', 'CONTACTED', 'WAITING_PAYMENT', 'PAID_OFFLINE', 'PREPARING', 'SHIPPED', 'COMPLETED', 'CANCELLED')
    NOT NULL DEFAULT 'NEW';
