-- =============================================================================
--  005 - Organigramma, impostazioni e integrazioni esterne
-- =============================================================================

-- Ruoli del direttivo: modificabili dagli amministratori senza toccare codice.
CREATE TABLE organization_roles (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100) NOT NULL,
    slug        VARCHAR(100) NOT NULL,
    description VARCHAR(300) NULL DEFAULT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_organization_roles_slug (slug)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


CREATE TABLE organization_members (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id      INT UNSIGNED NULL DEFAULT NULL,
    full_name    VARCHAR(120) NOT NULL,
    -- Titolo mostrato: puo differire dal nome del ruolo (es. "Vice e cassiere").
    role_title   VARCHAR(120) NULL DEFAULT NULL,
    bio          VARCHAR(600) NULL DEFAULT NULL,
    photo_key    VARCHAR(190) NULL DEFAULT NULL,
    photo_extension VARCHAR(8) NULL DEFAULT NULL,
    email        VARCHAR(190) NULL DEFAULT NULL,
    phone        VARCHAR(40) NULL DEFAULT NULL,
    member_since SMALLINT UNSIGNED NULL DEFAULT NULL,
    sort_order   INT NOT NULL DEFAULT 0,
    is_visible   TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL,
    updated_at   DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_organization_members_order (is_visible, sort_order),
    CONSTRAINT fk_organization_members_role FOREIGN KEY (role_id) REFERENCES organization_roles (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Impostazioni modificabili dal pannello. Solo valori NON sensibili: chiavi API
-- e credenziali restano nel file .env, fuori dal database e fuori dal repository.
CREATE TABLE site_settings (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_name  VARCHAR(50) NOT NULL DEFAULT 'generale',
    key_name    VARCHAR(100) NOT NULL,
    value       TEXT NULL DEFAULT NULL,
    type        ENUM('string', 'text', 'int', 'float', 'bool', 'json', 'url', 'email', 'color') NOT NULL DEFAULT 'string',
    label       VARCHAR(150) NOT NULL,
    description VARCHAR(300) NULL DEFAULT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    updated_by  INT UNSIGNED NULL DEFAULT NULL,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_site_settings_key (key_name),
    KEY idx_site_settings_group (group_name, sort_order),
    CONSTRAINT fk_site_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Calendario partite: copia locale alimentata dal cron. Il frontend legge solo
-- questa tabella, quindi il sito resta in piedi anche se l'API e irraggiungibile.
CREATE TABLE football_matches (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider       VARCHAR(30) NOT NULL DEFAULT 'mock',
    external_id    VARCHAR(60) NOT NULL,
    competition    VARCHAR(100) NOT NULL,
    competition_code VARCHAR(20) NULL DEFAULT NULL,
    round_label    VARCHAR(60) NULL DEFAULT NULL,
    season         SMALLINT UNSIGNED NULL DEFAULT NULL,
    home_team      VARCHAR(100) NOT NULL,
    away_team      VARCHAR(100) NOT NULL,
    home_team_logo VARCHAR(500) NULL DEFAULT NULL,
    away_team_logo VARCHAR(500) NULL DEFAULT NULL,
    -- Ridondanti ma comodissime: evitano di ricalcolare in ogni template chi
    -- gioca in casa e chi e l'avversario della Fiorentina.
    is_home        TINYINT(1) NOT NULL DEFAULT 1,
    opponent       VARCHAR(100) NOT NULL,
    venue          VARCHAR(150) NULL DEFAULT NULL,
    kickoff_at     DATETIME NOT NULL,
    status         ENUM('scheduled', 'live', 'finished', 'postponed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    home_score     TINYINT UNSIGNED NULL DEFAULT NULL,
    away_score     TINYINT UNSIGNED NULL DEFAULT NULL,
    -- Partita inserita a mano dall'amministratore: il sync non la sovrascrive.
    is_manual      TINYINT(1) NOT NULL DEFAULT 0,
    synced_at      DATETIME NULL DEFAULT NULL,
    created_at     DATETIME NOT NULL,
    updated_at     DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_football_match (provider, external_id),
    KEY idx_football_kickoff (kickoff_at, status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Cache dei contenuti social. Le anteprime vengono scaricate in locale durante
-- la sincronizzazione: gli URL dei CDN Meta scadono, e senza copia locale la
-- homepage mostrerebbe riquadri vuoti dopo qualche giorno.
CREATE TABLE social_posts (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider         ENUM('instagram', 'facebook', 'youtube') NOT NULL,
    external_id      VARCHAR(120) NOT NULL,
    permalink        VARCHAR(500) NOT NULL,
    media_type       ENUM('image', 'video', 'carousel', 'text') NOT NULL DEFAULT 'image',
    media_url        VARCHAR(500) NULL DEFAULT NULL,
    thumbnail_url    VARCHAR(500) NULL DEFAULT NULL,
    local_thumb_key  VARCHAR(190) NULL DEFAULT NULL,
    caption          TEXT NULL DEFAULT NULL,
    author           VARCHAR(100) NULL DEFAULT NULL,
    published_at     DATETIME NULL DEFAULT NULL,
    is_visible       TINYINT(1) NOT NULL DEFAULT 1,
    synced_at        DATETIME NOT NULL,
    created_at       DATETIME NOT NULL,
    updated_at       DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_social_post (provider, external_id),
    KEY idx_social_published (provider, published_at),
    KEY idx_social_visible_published (is_visible, published_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


CREATE TABLE contact_messages (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(120) NOT NULL,
    email      VARCHAR(190) NOT NULL,
    subject    VARCHAR(200) NOT NULL,
    message    TEXT NOT NULL,
    ip         VARCHAR(45) NULL DEFAULT NULL,
    user_agent VARCHAR(255) NULL DEFAULT NULL,
    status     ENUM('new', 'read', 'replied', 'archived', 'spam') NOT NULL DEFAULT 'new',
    read_at    DATETIME NULL DEFAULT NULL,
    read_by    INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_contact_status_created (status, created_at),
    CONSTRAINT fk_contact_read_by FOREIGN KEY (read_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- @DOWN

DROP TABLE IF EXISTS contact_messages;
DROP TABLE IF EXISTS social_posts;
DROP TABLE IF EXISTS football_matches;
DROP TABLE IF EXISTS site_settings;
DROP TABLE IF EXISTS organization_members;
DROP TABLE IF EXISTS organization_roles;
