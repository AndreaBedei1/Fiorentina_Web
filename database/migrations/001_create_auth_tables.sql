-- =============================================================================
--  001 - Amministratori, autenticazione, sicurezza e audit
-- =============================================================================
--  Il sito pubblico non ha utenti registrati: questa tabella contiene solo gli
--  amministratori del gruppo. Da qui la scelta di ENUM per il ruolo (i ruoli
--  sono due e non cambieranno spesso) invece di una tabella permessi completa,
--  che sarebbe sovradimensionata.
-- =============================================================================

CREATE TABLE users (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                 VARCHAR(120) NOT NULL,
    email                VARCHAR(190) NOT NULL,
    -- NULL finche l'invito non e stato accettato e la password scelta.
    password_hash        VARCHAR(255) NULL DEFAULT NULL,
    role                 ENUM('SUPER_ADMIN', 'ADMIN') NOT NULL DEFAULT 'ADMIN',
    status               ENUM('pending', 'active', 'blocked') NOT NULL DEFAULT 'pending',
    phone                VARCHAR(40) NULL DEFAULT NULL,
    last_login_at        DATETIME NULL DEFAULT NULL,
    last_login_ip        VARCHAR(45) NULL DEFAULT NULL,
    password_changed_at  DATETIME NULL DEFAULT NULL,
    -- Le sessioni aperte prima di questo istante non sono piu valide: e il
    -- meccanismo che disconnette immediatamente un account bloccato.
    sessions_valid_after DATETIME NULL DEFAULT NULL,
    created_by           INT UNSIGNED NULL DEFAULT NULL,
    created_at           DATETIME NOT NULL,
    updated_at           DATETIME NOT NULL,
    -- Soft delete: un amministratore rimosso resta referenziabile dall'audit log.
    deleted_at           DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_email (email),
    KEY idx_users_status_role (status, role),
    KEY idx_users_deleted_at (deleted_at),
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Inviti a diventare amministratore. Del token salviamo solo l'hash SHA-256:
-- un dump del database non permette di riutilizzare gli inviti pendenti.
CREATE TABLE admin_invites (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email       VARCHAR(190) NOT NULL,
    name        VARCHAR(120) NOT NULL,
    role        ENUM('SUPER_ADMIN', 'ADMIN') NOT NULL DEFAULT 'ADMIN',
    token_hash  CHAR(64) NOT NULL,
    user_id     INT UNSIGNED NULL DEFAULT NULL,
    invited_by  INT UNSIGNED NULL DEFAULT NULL,
    expires_at  DATETIME NOT NULL,
    accepted_at DATETIME NULL DEFAULT NULL,
    revoked_at  DATETIME NULL DEFAULT NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_admin_invites_token (token_hash),
    KEY idx_admin_invites_email (email),
    KEY idx_admin_invites_expires (expires_at),
    CONSTRAINT fk_admin_invites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_invites_inviter FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


CREATE TABLE password_reset_tokens (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    token_hash   CHAR(64) NOT NULL,
    requested_ip VARCHAR(45) NULL DEFAULT NULL,
    expires_at   DATETIME NOT NULL,
    used_at      DATETIME NULL DEFAULT NULL,
    created_at   DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_password_reset_token (token_hash),
    KEY idx_password_reset_user (user_id),
    KEY idx_password_reset_expires (expires_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Storico dei tentativi di accesso: alimenta il blocco brute force e da agli
-- amministratori visibilita sugli accessi sospetti.
CREATE TABLE login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email        VARCHAR(190) NOT NULL,
    ip           VARCHAR(45) NOT NULL,
    successful   TINYINT(1) NOT NULL DEFAULT 0,
    user_agent   VARCHAR(255) NULL DEFAULT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_attempts_email_time (email, attempted_at),
    KEY idx_login_attempts_ip_time (ip, attempted_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Contatori generici di rate limiting (form contatti, ordini, reset password).
-- Su database perche l'hosting condiviso non offre Redis o APCu affidabile.
CREATE TABLE rate_limits (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket_key VARCHAR(190) NOT NULL,
    attempts   INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_rate_limits_key (bucket_key),
    KEY idx_rate_limits_expires (expires_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Registro delle azioni amministrative.
-- I dati dell'autore sono duplicati (email e ruolo al momento del fatto) perche
-- il log deve restare leggibile anche dopo che l'account e stato rimosso.
CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL DEFAULT NULL,
    user_email  VARCHAR(190) NULL DEFAULT NULL,
    user_role   VARCHAR(20) NULL DEFAULT NULL,
    action      VARCHAR(60) NOT NULL,
    entity_type VARCHAR(60) NULL DEFAULT NULL,
    entity_id   INT UNSIGNED NULL DEFAULT NULL,
    description VARCHAR(255) NULL DEFAULT NULL,
    -- Contesto aggiuntivo. Non deve mai contenere password, token o segreti.
    metadata    JSON NULL DEFAULT NULL,
    ip          VARCHAR(45) NULL DEFAULT NULL,
    user_agent  VARCHAR(255) NULL DEFAULT NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_created_at (created_at),
    KEY idx_audit_user (user_id),
    KEY idx_audit_action (action),
    KEY idx_audit_entity (entity_type, entity_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- @DOWN

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS password_reset_tokens;
DROP TABLE IF EXISTS admin_invites;
DROP TABLE IF EXISTS users;
