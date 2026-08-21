-- Le notizie non hanno piu l'attributo "in evidenza".
--
-- Serviva a far risaltare un articolo negli elenchi, ma con una manciata di
-- notizie all'anno l'ordine cronologico basta: la piu recente sta gia in cima.
-- Un attributo che non cambia niente e solo un campo in piu da spiegare a chi
-- usa il pannello.
--
-- Eventi e prodotti mantengono il loro: li la messa in evidenza decide cosa
-- compare in homepage, quindi ha un effetto concreto.

ALTER TABLE news DROP COLUMN is_featured;

-- @DOWN

ALTER TABLE news
    ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
