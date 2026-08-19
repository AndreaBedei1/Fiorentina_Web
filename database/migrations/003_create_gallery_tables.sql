-- =============================================================================
--  003 - Galleria fotografica
-- =============================================================================
--  L'archivio del gruppo contiene migliaia di fotografie: la galleria e
--  organizzata per album e paginata, mai in scorrimento infinito su tutto
--  l'archivio. Gli indici qui sotto servono esattamente le query di elenco.
-- =============================================================================

CREATE TABLE albums (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title            VARCHAR(200) NOT NULL,
    slug             VARCHAR(200) NOT NULL,
    description      TEXT NULL DEFAULT NULL,
    event_date       DATE NULL DEFAULT NULL,
    -- Ridondante rispetto a event_date, ma rende il filtro per anno un semplice
    -- confronto indicizzato invece di una funzione sulla colonna.
    year             SMALLINT UNSIGNED NULL DEFAULT NULL,
    category         ENUM('stadio', 'trasferte', 'eventi', 'raduni', 'storico', 'altro') NOT NULL DEFAULT 'altro',
    cover_photo_id   INT UNSIGNED NULL DEFAULT NULL,
    status           ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    sort_order       INT NOT NULL DEFAULT 0,
    -- Contatore denormalizzato: evita una COUNT correlata su ogni card di elenco.
    photos_count     INT UNSIGNED NOT NULL DEFAULT 0,
    meta_description VARCHAR(300) NULL DEFAULT NULL,
    created_by       INT UNSIGNED NULL DEFAULT NULL,
    created_at       DATETIME NOT NULL,
    updated_at       DATETIME NOT NULL,
    deleted_at       DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_albums_slug (slug),
    KEY idx_albums_status_date (status, event_date),
    KEY idx_albums_year (year),
    KEY idx_albums_category (category),
    KEY idx_albums_deleted_at (deleted_at),
    CONSTRAINT fk_albums_author FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


CREATE TABLE photos (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    album_id        INT UNSIGNED NOT NULL,
    /*
     * Identificativo di archiviazione generato dal server, del tipo
     * "2026/08/9f2c8d3a1b4e5f60". Da questa chiave App\Services\Media\MediaPaths
     * ricava sia il percorso privato dell'originale sia i file pubblici gia
     * elaborati. Un'unica colonna al posto di sei percorsi separati mantiene
     * coerente la convenzione dei nomi.
     */
    storage_key     VARCHAR(190) NOT NULL,
    extension       VARCHAR(8) NOT NULL DEFAULT 'jpg',
    original_name   VARCHAR(190) NULL DEFAULT NULL,
    title           VARCHAR(200) NULL DEFAULT NULL,
    caption         VARCHAR(500) NULL DEFAULT NULL,
    -- Testo alternativo: obbligatorio per l'accessibilita, con fallback generato.
    alt_text        VARCHAR(300) NULL DEFAULT NULL,
    width           INT UNSIGNED NULL DEFAULT NULL,
    height          INT UNSIGNED NULL DEFAULT NULL,
    filesize        INT UNSIGNED NULL DEFAULT NULL,
    taken_at        DATETIME NULL DEFAULT NULL,
    has_watermark   TINYINT(1) NOT NULL DEFAULT 0,
    sort_order      INT NOT NULL DEFAULT 0,
    status          ENUM('published', 'hidden') NOT NULL DEFAULT 'published',
    uploaded_by     INT UNSIGNED NULL DEFAULT NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_photos_storage_key (storage_key),
    KEY idx_photos_album_order (album_id, sort_order, id),
    KEY idx_photos_status (status),
    CONSTRAINT fk_photos_album FOREIGN KEY (album_id) REFERENCES albums (id) ON DELETE CASCADE,
    CONSTRAINT fk_photos_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- La copertina viene aggiunta dopo la creazione di photos per evitare una
-- dipendenza circolare fra le due tabelle in fase di creazione.
ALTER TABLE albums
    ADD CONSTRAINT fk_albums_cover FOREIGN KEY (cover_photo_id) REFERENCES photos (id) ON DELETE SET NULL;

-- @DOWN

ALTER TABLE albums DROP FOREIGN KEY fk_albums_cover;
DROP TABLE IF EXISTS photos;
DROP TABLE IF EXISTS albums;
