-- =============================================================================
--  002 - Notizie, eventi e pagine editoriali
-- =============================================================================

CREATE TABLE news (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title            VARCHAR(200) NOT NULL,
    slug             VARCHAR(200) NOT NULL,
    excerpt          VARCHAR(400) NULL DEFAULT NULL,
    -- HTML gia sanificato in scrittura da HtmlSanitizer: in lettura non serve
    -- ripulirlo di nuovo, cosa che renderebbe ogni pagina piu lenta.
    content          MEDIUMTEXT NULL DEFAULT NULL,
    image_key        VARCHAR(190) NULL DEFAULT NULL,
    image_alt        VARCHAR(200) NULL DEFAULT NULL,
    author_id        INT UNSIGNED NULL DEFAULT NULL,
    status           ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    published_at     DATETIME NULL DEFAULT NULL,
    is_featured      TINYINT(1) NOT NULL DEFAULT 0,
    meta_title       VARCHAR(200) NULL DEFAULT NULL,
    meta_description VARCHAR(300) NULL DEFAULT NULL,
    views            INT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL,
    updated_at       DATETIME NOT NULL,
    deleted_at       DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_news_slug (slug),
    -- Indice che serve la query piu frequente del sito: ultime notizie pubblicate.
    KEY idx_news_status_published (status, published_at),
    KEY idx_news_deleted_at (deleted_at),
    CONSTRAINT fk_news_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Categorie evento: modificabili dagli amministratori, con icona e colore per
-- distinguere a colpo d'occhio le voci nel calendario.
CREATE TABLE event_categories (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(80) NOT NULL,
    slug        VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL DEFAULT NULL,
    -- Chiave simbolica dell'icona (bus, users, dinner, party, flag, star, calendar).
    icon        VARCHAR(30) NOT NULL DEFAULT 'calendar',
    color       VARCHAR(20) NOT NULL DEFAULT 'viola',
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_event_categories_slug (slug)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


CREATE TABLE events (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title             VARCHAR(200) NOT NULL,
    slug              VARCHAR(200) NOT NULL,
    short_description VARCHAR(400) NULL DEFAULT NULL,
    description       MEDIUMTEXT NULL DEFAULT NULL,
    category_id       INT UNSIGNED NULL DEFAULT NULL,
    starts_at         DATETIME NOT NULL,
    ends_at           DATETIME NULL DEFAULT NULL,
    location_name     VARCHAR(150) NULL DEFAULT NULL,
    address           VARCHAR(255) NULL DEFAULT NULL,
    city              VARCHAR(100) NULL DEFAULT NULL,
    meeting_point     VARCHAR(255) NULL DEFAULT NULL,
    meeting_at        DATETIME NULL DEFAULT NULL,
    maps_url          VARCHAR(500) NULL DEFAULT NULL,
    image_key         VARCHAR(190) NULL DEFAULT NULL,
    image_alt         VARCHAR(200) NULL DEFAULT NULL,
    cost              DECIMAL(8, 2) NULL DEFAULT NULL,
    cost_note         VARCHAR(150) NULL DEFAULT NULL,
    info              TEXT NULL DEFAULT NULL,
    contact_info      VARCHAR(255) NULL DEFAULT NULL,
    -- Solo informativo: non gestiamo prenotazioni online (requisito esplicito).
    limited_seats     TINYINT(1) NOT NULL DEFAULT 0,
    seats             INT UNSIGNED NULL DEFAULT NULL,
    status            ENUM('draft', 'published', 'cancelled', 'archived') NOT NULL DEFAULT 'draft',
    is_featured       TINYINT(1) NOT NULL DEFAULT 0,
    meta_title        VARCHAR(200) NULL DEFAULT NULL,
    meta_description  VARCHAR(300) NULL DEFAULT NULL,
    created_by        INT UNSIGNED NULL DEFAULT NULL,
    created_at        DATETIME NOT NULL,
    updated_at        DATETIME NOT NULL,
    deleted_at        DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_events_slug (slug),
    KEY idx_events_status_starts (status, starts_at),
    KEY idx_events_category (category_id),
    KEY idx_events_deleted_at (deleted_at),
    CONSTRAINT fk_events_category FOREIGN KEY (category_id) REFERENCES event_categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_events_author FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Pagine editoriali (Chi siamo, Diventa socio, Contatti, Privacy, Cookie).
-- Non e un page builder generico: e un contenitore con intestazione, corpo e
-- blocchi tipizzati, sufficiente per chi non e tecnico.
CREATE TABLE pages (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug             VARCHAR(120) NOT NULL,
    title            VARCHAR(200) NOT NULL,
    subtitle         VARCHAR(300) NULL DEFAULT NULL,
    intro            TEXT NULL DEFAULT NULL,
    content          MEDIUMTEXT NULL DEFAULT NULL,
    hero_image_key   VARCHAR(190) NULL DEFAULT NULL,
    hero_image_alt   VARCHAR(200) NULL DEFAULT NULL,
    meta_title       VARCHAR(200) NULL DEFAULT NULL,
    meta_description VARCHAR(300) NULL DEFAULT NULL,
    status           ENUM('draft', 'published') NOT NULL DEFAULT 'published',
    -- Le pagine di sistema hanno una rotta dedicata: si modificano, non si eliminano.
    is_system        TINYINT(1) NOT NULL DEFAULT 0,
    updated_by       INT UNSIGNED NULL DEFAULT NULL,
    created_at       DATETIME NOT NULL,
    updated_at       DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_pages_slug (slug),
    CONSTRAINT fk_pages_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


CREATE TABLE page_blocks (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    page_id    INT UNSIGNED NOT NULL,
    type       ENUM('text', 'list', 'steps', 'highlight', 'cta', 'faq', 'stats', 'timeline') NOT NULL DEFAULT 'text',
    title      VARCHAR(200) NULL DEFAULT NULL,
    subtitle   VARCHAR(300) NULL DEFAULT NULL,
    body       TEXT NULL DEFAULT NULL,
    -- Elenchi, passaggi, FAQ e statistiche: array di oggetti tipizzati per blocco.
    items      JSON NULL DEFAULT NULL,
    icon       VARCHAR(30) NULL DEFAULT NULL,
    link_url   VARCHAR(500) NULL DEFAULT NULL,
    link_label VARCHAR(100) NULL DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_page_blocks_page (page_id, sort_order),
    CONSTRAINT fk_page_blocks_page FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- @DOWN

DROP TABLE IF EXISTS page_blocks;
DROP TABLE IF EXISTS pages;
DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS event_categories;
DROP TABLE IF EXISTS news;
