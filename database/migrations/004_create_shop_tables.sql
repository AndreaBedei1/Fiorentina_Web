-- =============================================================================
--  004 - Merchandising e ordini
-- =============================================================================
--  Il sito NON gestisce pagamenti online: un ordine e una richiesta registrata,
--  seguita da email al responsabile merchandising e al cliente con le
--  istruzioni per il pagamento offline. Nessun dato di pagamento viene mai
--  raccolto, quindi nessuna colonna qui puo contenerlo.
-- =============================================================================

CREATE TABLE product_categories (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100) NOT NULL,
    slug        VARCHAR(100) NOT NULL,
    description VARCHAR(400) NULL DEFAULT NULL,
    image_key   VARCHAR(190) NULL DEFAULT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    status      ENUM('active', 'hidden') NOT NULL DEFAULT 'active',
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_product_categories_slug (slug)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


CREATE TABLE products (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id       INT UNSIGNED NULL DEFAULT NULL,
    name              VARCHAR(200) NOT NULL,
    slug              VARCHAR(200) NOT NULL,
    short_description VARCHAR(300) NULL DEFAULT NULL,
    description       MEDIUMTEXT NULL DEFAULT NULL,
    -- DECIMAL e non FLOAT: sui prezzi l'aritmetica binaria produce differenze
    -- di centesimi che si accumulano nei totali d'ordine.
    price             DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    compare_at_price  DECIMAL(8, 2) NULL DEFAULT NULL,
    availability      ENUM('in_stock', 'out_of_stock', 'preorder', 'discontinued') NOT NULL DEFAULT 'in_stock',
    -- La gestione quantita e facoltativa: molti gadget si ordinano su richiesta.
    track_quantity    TINYINT(1) NOT NULL DEFAULT 0,
    quantity          INT NULL DEFAULT NULL,
    is_featured       TINYINT(1) NOT NULL DEFAULT 0,
    status            ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    sort_order        INT NOT NULL DEFAULT 0,
    meta_title        VARCHAR(200) NULL DEFAULT NULL,
    meta_description  VARCHAR(300) NULL DEFAULT NULL,
    created_by        INT UNSIGNED NULL DEFAULT NULL,
    created_at        DATETIME NOT NULL,
    updated_at        DATETIME NOT NULL,
    deleted_at        DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_products_slug (slug),
    KEY idx_products_status_featured (status, is_featured),
    KEY idx_products_category (category_id),
    KEY idx_products_deleted_at (deleted_at),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES product_categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_products_author FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


CREATE TABLE product_images (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id  INT UNSIGNED NOT NULL,
    storage_key VARCHAR(190) NOT NULL,
    extension   VARCHAR(8) NOT NULL DEFAULT 'jpg',
    alt_text    VARCHAR(300) NULL DEFAULT NULL,
    width       INT UNSIGNED NULL DEFAULT NULL,
    height      INT UNSIGNED NULL DEFAULT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    is_primary  TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_product_images_key (storage_key),
    KEY idx_product_images_product (product_id, sort_order),
    CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Una variante e una combinazione acquistabile (taglia, colore o entrambi).
-- Modello volutamente piatto: il catalogo del gruppo e piccolo e una matrice
-- opzione/valore completa complicherebbe l'interfaccia senza vantaggi reali.
CREATE TABLE product_variants (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id     INT UNSIGNED NOT NULL,
    label          VARCHAR(80) NOT NULL,
    size           VARCHAR(40) NULL DEFAULT NULL,
    color          VARCHAR(40) NULL DEFAULT NULL,
    sku            VARCHAR(60) NULL DEFAULT NULL,
    -- Differenza rispetto al prezzo base (positiva o negativa).
    price_modifier DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    quantity       INT NULL DEFAULT NULL,
    is_available   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order     INT NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL,
    updated_at     DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_product_variant_label (product_id, label),
    KEY idx_product_variants_product (product_id, sort_order),
    CONSTRAINT fk_product_variants_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


CREATE TABLE orders (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_number          VARCHAR(20) NOT NULL,
    status                ENUM(
        'NEW', 'CONTACTED', 'WAITING_PAYMENT', 'PAID_OFFLINE',
        'PREPARING', 'SHIPPED', 'COMPLETED', 'CANCELLED'
    ) NOT NULL DEFAULT 'NEW',
    customer_first_name   VARCHAR(80) NOT NULL,
    customer_last_name    VARCHAR(80) NOT NULL,
    customer_email        VARCHAR(190) NOT NULL,
    customer_phone        VARCHAR(40) NOT NULL,
    shipping_method       ENUM('delivery', 'pickup') NOT NULL DEFAULT 'delivery',
    shipping_address      VARCHAR(255) NULL DEFAULT NULL,
    shipping_postal_code  VARCHAR(10) NULL DEFAULT NULL,
    shipping_city         VARCHAR(100) NULL DEFAULT NULL,
    shipping_province     VARCHAR(4) NULL DEFAULT NULL,
    shipping_country      VARCHAR(2) NOT NULL DEFAULT 'IT',
    notes                 TEXT NULL DEFAULT NULL,
    admin_notes           TEXT NULL DEFAULT NULL,
    subtotal              DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    shipping_cost         DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    total                 DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    items_count           INT UNSIGNED NOT NULL DEFAULT 0,
    ip                    VARCHAR(45) NULL DEFAULT NULL,
    user_agent            VARCHAR(255) NULL DEFAULT NULL,
    customer_notified_at  DATETIME NULL DEFAULT NULL,
    manager_notified_at   DATETIME NULL DEFAULT NULL,
    created_at            DATETIME NOT NULL,
    updated_at            DATETIME NOT NULL,
    -- Un ordine non sparisce mai davvero: "elimina" imposta questa colonna.
    deleted_at            DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_orders_number (order_number),
    KEY idx_orders_status_created (status, created_at),
    KEY idx_orders_email (customer_email),
    KEY idx_orders_deleted_at (deleted_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Righe d'ordine con snapshot di nome, variante e prezzo: se domani il prodotto
-- cambia prezzo o viene eliminato, l'ordine storico resta leggibile com'era.
CREATE TABLE order_items (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id      INT UNSIGNED NOT NULL,
    product_id    INT UNSIGNED NULL DEFAULT NULL,
    variant_id    INT UNSIGNED NULL DEFAULT NULL,
    product_name  VARCHAR(200) NOT NULL,
    product_slug  VARCHAR(200) NULL DEFAULT NULL,
    variant_label VARCHAR(80) NULL DEFAULT NULL,
    image_key     VARCHAR(190) NULL DEFAULT NULL,
    unit_price    DECIMAL(8, 2) NOT NULL,
    quantity      INT UNSIGNED NOT NULL DEFAULT 1,
    line_total    DECIMAL(10, 2) NOT NULL,
    created_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_order_items_order (order_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
    CONSTRAINT fk_order_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


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


-- Contatore per la numerazione progressiva annuale (BF-2026-000001).
-- Una riga per anno, aggiornata con SELECT ... FOR UPDATE dentro la transazione
-- di creazione ordine: garantisce numeri unici anche con richieste simultanee.
CREATE TABLE order_sequences (
    year        SMALLINT UNSIGNED NOT NULL,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (year)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- @DOWN

DROP TABLE IF EXISTS order_sequences;
DROP TABLE IF EXISTS order_status_history;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS product_variants;
DROP TABLE IF EXISTS product_images;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS product_categories;
