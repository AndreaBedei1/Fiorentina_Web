-- Il registro attivita non esiste piu.
--
-- Registrava chi aveva creato, modificato o eliminato cosa, e si leggeva da
-- una pagina del pannello. La pagina e stata tolta perche non serviva a
-- nessuno, e una tabella che si riempie e che nessuno legge e solo una
-- tabella che cresce.
--
-- Con lei se ne vanno le chiamate sparse nei controller e nei servizi: erano
-- sessantotto, e ognuna era una riga in piu da leggere per capire cosa
-- facesse davvero il metodo che la conteneva.
--
-- Cosa si perde, detto chiaramente: se un domani sparisce un album e nessuno
-- si ricorda chi l'ha eliminato, non c'e piu modo di saperlo. Con un
-- pannello a piu mani e una rinuncia vera, ed e una scelta presa sapendolo.
--
-- Restano i tentativi di accesso in login_attempts, che non sono un registro
-- ma il meccanismo che blocca chi prova mille password di fila.

DROP TABLE audit_logs;

-- @DOWN

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
