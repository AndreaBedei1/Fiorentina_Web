-- Del direttivo bastano nome, cognome, ruolo e fotografia.
--
-- La scheda di una persona chiedeva undici cose: nome e cognome insieme in un
-- campo solo, un ruolo da scegliere in una tendina, un "titolo mostrato" che
-- serviva a scavalcare la tendina quando non bastava, biografia, email,
-- telefono, anno di iscrizione, ordinamento e una spunta per nasconderla.
--
-- Chi arriva su "Chi siamo" vuole sapere a chi rivolgersi: una faccia, un
-- nome e cosa fa. Il resto non compariva da nessuna parte - email e telefono
-- erano dichiarati come non pubblici - oppure duplicava se stesso: il ruolo
-- esisteva due volte, una come riga di una tabella e una come testo libero,
-- e in tutti e sei i casi seminati vincevano il testo libero.
--
-- Quindi: il ruolo diventa quello che e sempre stato, una scritta. La tabella
-- dei ruoli sparisce, dopo aver travasato il nome del ruolo in chi non aveva
-- gia il testo scritto a mano. Nome e cognome si separano, perche sono due
-- cose e servono separate per ordinare l'elenco.
--
-- Le persone compaiono nell'ordine in cui sono state inserite: la colonna di
-- ordinamento non c'e piu, e chiedere un numero d'ordine a chi sta scrivendo
-- il nome del presidente sarebbe una domanda in piu senza una risposta ovvia.

ALTER TABLE organization_members
    ADD COLUMN first_name VARCHAR(60) NOT NULL DEFAULT '' AFTER id,
    ADD COLUMN last_name  VARCHAR(60) NOT NULL DEFAULT '' AFTER first_name;

-- Il nome intero si spezza al primo spazio: quello che viene prima e il nome,
-- tutto il resto e il cognome. Sui segnaposto "Nome Cognome" funziona, e su
-- un elenco che si conta sulle dita si corregge a mano in un minuto.
UPDATE organization_members
   SET first_name = SUBSTRING_INDEX(full_name, ' ', 1),
       last_name  = TRIM(SUBSTRING(full_name, LENGTH(SUBSTRING_INDEX(full_name, ' ', 1)) + 2));

-- Chi non ha il ruolo scritto a mano si tiene il nome del ruolo che aveva.
UPDATE organization_members m
  LEFT JOIN organization_roles r ON r.id = m.role_id
   SET m.role_title = r.name
 WHERE (m.role_title IS NULL OR m.role_title = '') AND r.name IS NOT NULL;

ALTER TABLE organization_members DROP FOREIGN KEY fk_organization_members_role;

ALTER TABLE organization_members
    DROP COLUMN role_id,
    DROP COLUMN full_name,
    DROP COLUMN bio,
    DROP COLUMN email,
    DROP COLUMN phone,
    DROP COLUMN member_since,
    DROP COLUMN sort_order,
    DROP COLUMN is_visible,
    CHANGE COLUMN role_title role VARCHAR(120) NULL DEFAULT NULL;

DROP TABLE organization_roles;

-- @DOWN

CREATE TABLE organization_roles (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100) NOT NULL,
    slug        VARCHAR(100) NOT NULL,
    description VARCHAR(300) NULL DEFAULT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_organization_roles_slug (slug)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE organization_members
    CHANGE COLUMN role role_title VARCHAR(120) NULL DEFAULT NULL,
    ADD COLUMN role_id      INT UNSIGNED NULL DEFAULT NULL AFTER id,
    ADD COLUMN full_name    VARCHAR(120) NOT NULL DEFAULT '' AFTER role_id,
    ADD COLUMN bio          VARCHAR(600) NULL DEFAULT NULL AFTER role_title,
    ADD COLUMN email        VARCHAR(190) NULL DEFAULT NULL AFTER photo_extension,
    ADD COLUMN phone        VARCHAR(40) NULL DEFAULT NULL AFTER email,
    ADD COLUMN member_since SMALLINT UNSIGNED NULL DEFAULT NULL AFTER phone,
    ADD COLUMN sort_order   INT NOT NULL DEFAULT 0 AFTER member_since,
    ADD COLUMN is_visible   TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order,
    ADD CONSTRAINT fk_organization_members_role FOREIGN KEY (role_id) REFERENCES organization_roles (id) ON DELETE SET NULL;

UPDATE organization_members SET full_name = TRIM(CONCAT(first_name, ' ', last_name));

ALTER TABLE organization_members
    DROP COLUMN first_name,
    DROP COLUMN last_name;
