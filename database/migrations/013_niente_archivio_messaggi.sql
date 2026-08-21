-- I messaggi dal modulo contatti non si conservano.
--
-- Arrivavano per email e finivano anche in questa tabella, leggibili dal
-- pannello alla voce "Messaggi". Due copie della stessa cosa, di cui una che
-- nessuno guardava: chi riceve la notifica risponde dal programma di posta,
-- non entrando nell'area riservata.
--
-- La copia che resta indietro non e gratis. Sono nomi, indirizzi email e testi
-- scritti da persone, che vanno protetti finche esistono e cancellati quando
-- non servono piu. Tenerli senza usarli e il modo peggiore di trattarli.
--
-- Da adesso l'email e l'unica copia. Il controller se ne e accorto: se la
-- posta non parte lo dice a chi ha scritto, invece di annunciare un invio
-- riuscito e lasciarlo ad aspettare una risposta che nessuno leggera.

DROP TABLE IF EXISTS contact_messages;

-- @DOWN

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
