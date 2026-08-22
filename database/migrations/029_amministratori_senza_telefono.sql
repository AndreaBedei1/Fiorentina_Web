-- Il telefono dell'amministratore non serviva a nessuno.
--
-- Era un campo nel profilo personale, e finiva li: nessuna pagina lo
-- mostrava, nessuna email lo usava, nessuno lo chiamava. Un dato personale
-- raccolto e conservato senza uno scopo e un dato da non raccogliere.
--
-- Il telefono che serve davvero e quello del gruppo, che sta nelle
-- impostazioni e compare nei contatti, e quello di chi ordina, che sta sulla
-- riga dell'ordine. Nessuno dei due passa da qui.

ALTER TABLE users DROP COLUMN phone;

-- @DOWN

ALTER TABLE users ADD COLUMN phone VARCHAR(40) NULL DEFAULT NULL AFTER email;
