-- Le righe marcate come eliminate se ne vanno davvero.
--
-- Fino a ieri "elimina" scriveva una data nella colonna deleted_at: la riga
-- spariva dal sito e dal pannello ma restava a database, e i file delle sue
-- immagini restavano su disco. Il ripristino, motivo per cui era stato fatto
-- cosi, non esisteva da nessuna parte: sarebbe stato possibile solo aprendo
-- il database a mano.
--
-- Adesso eliminare elimina, e queste sono le righe rimaste indietro. I file
-- che nessuna riga rivendica si tolgono con `composer media:clean`.
--
-- Le eccezioni restano: un ordine e la richiesta di una persona reale e non
-- si cancella, e un account amministratore eliminato resta agganciato alle
-- voci che ha lasciato nel registro attivita. Quelle due tabelle non si
-- toccano.

DELETE FROM news WHERE deleted_at IS NOT NULL;
DELETE FROM events WHERE deleted_at IS NOT NULL;
DELETE FROM albums WHERE deleted_at IS NOT NULL;
DELETE FROM products WHERE deleted_at IS NOT NULL;

-- @DOWN
--
-- Nessuna: le righe eliminate non si riportano indietro, ed e esattamente il
-- comportamento che questa migrazione mette in chiaro.
