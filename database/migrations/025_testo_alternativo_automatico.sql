-- Il testo alternativo non si chiede piu a chi carica una fotografia.
--
-- Ogni immagine deve avere una descrizione per chi non la vede: e una regola
-- di accessibilita, non un vezzo. Ma chiederla a chi sta caricando le foto di
-- una trasferta significa chiedere una cosa di cui non sa niente, in un
-- momento in cui ha altro per la testa - e quello che si ottiene, quando si
-- ottiene, e la parola "foto" ripetuta quaranta volte, che per uno screen
-- reader vale quanto il silenzio.
--
-- Il sito adesso se lo scrive da solo, prendendo l'unica cosa che descrive
-- davvero l'immagine e che c'e sempre: il titolo di cio a cui appartiene. La
-- fotografia di una notizia si annuncia con il titolo della notizia, quella
-- di un prodotto col nome del prodotto, quella di un album col nome
-- dell'album. Non e la descrizione perfetta che scriverebbe un redattore
-- attento, ma e vera, e c'e sempre.
--
-- Le colonne quindi spariscono: tenerle vorrebbe dire tenere un campo che
-- nessun modulo compila piu.

ALTER TABLE news DROP COLUMN image_alt;
ALTER TABLE events DROP COLUMN image_alt;
ALTER TABLE photos DROP COLUMN alt_text;
ALTER TABLE product_images DROP COLUMN alt_text;
ALTER TABLE pages DROP COLUMN hero_image_alt;

-- @DOWN

ALTER TABLE news ADD COLUMN image_alt VARCHAR(200) NULL DEFAULT NULL AFTER image_key;
ALTER TABLE events ADD COLUMN image_alt VARCHAR(200) NULL DEFAULT NULL AFTER image_key;
ALTER TABLE photos ADD COLUMN alt_text VARCHAR(300) NULL DEFAULT NULL AFTER storage_key;
ALTER TABLE product_images ADD COLUMN alt_text VARCHAR(300) NULL DEFAULT NULL AFTER extension;
ALTER TABLE pages ADD COLUMN hero_image_alt VARCHAR(200) NULL DEFAULT NULL AFTER hero_image_key;
