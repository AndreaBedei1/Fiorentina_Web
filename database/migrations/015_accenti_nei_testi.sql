-- Accenti e apostrofi mancanti nei testi gia salvati a database.
--
-- "La tessera non e un pezzo di plastica", "Perche iscriversi", "a tutte le
-- attivita": parole tronche scritte senza accento e una "e" verbale al posto
-- di "e". Diverse erano gia state corrette nei seeder, ma i seeder non
-- riscrivono cio che trovano gia creato, quindi il database e rimasto
-- indietro. Questa migrazione lo allinea.
--
-- Ogni istruzione sostituisce una frase intera, non una parola isolata:
-- cambiare il solo "citta" avrebbe storpiato anche "cittadina", e cambiare la
-- sola "e" avrebbe accentato tutte le congiunzioni. Per lo stesso motivo sono
-- rimaste fuori le "e" che uniscono ("si sostiene e si organizza", "le quote
-- associative e il bilancio"): quelle vanno senza accento.
--
-- Sostituendo testo che deve esserci, l'istruzione non fa nulla dove la frase
-- e gia corretta o dove qualcuno ha riscritto il contenuto dal pannello.

UPDATE pages SET intro = REPLACE(intro, 'La tessera non e un pezzo di plastica: e il modo', 'La tessera non è un pezzo di plastica: è il modo') WHERE slug = 'diventa-socio';

UPDATE pages SET intro = REPLACE(intro, 'ha priorita sulle trasferte', 'ha priorità sulle trasferte') WHERE slug = 'diventa-socio';

UPDATE pages SET content = REPLACE(content, 'Questo testo e uno schema', 'Questo testo è uno schema') WHERE slug = 'privacy';

UPDATE pages SET content = REPLACE(content, 'Finalita e base giuridica', 'Finalità e base giuridica') WHERE slug = 'privacy';

UPDATE pages SET content = REPLACE(content, 'analisi statistica ne strumenti pubblicitari', 'analisi statistica né strumenti pubblicitari') WHERE slug = 'privacy';

UPDATE pages SET content = REPLACE(content, 'non e presente alcun banner', 'non è presente alcun banner') WHERE slug = 'cookie-policy';

UPDATE pages SET content = REPLACE(content, 'e comparira il relativo banner', 'e comparirà il relativo banner') WHERE slug = 'cookie-policy';

UPDATE page_blocks b JOIN pages p ON p.id = b.page_id
   SET b.title = REPLACE(b.title, 'Perche iscriversi', 'Perché iscriversi')
 WHERE p.slug = 'diventa-socio';

UPDATE page_blocks b JOIN pages p ON p.id = b.page_id
   SET b.items = REPLACE(b.items, 'Priorita sulle trasferte', 'Priorità sulle trasferte')
 WHERE p.slug = 'diventa-socio';

UPDATE page_blocks b JOIN pages p ON p.id = b.page_id
   SET b.items = REPLACE(b.items, 'Documento di identita valido', 'Documento di identità valido')
 WHERE p.slug = 'diventa-socio';

UPDATE page_blocks b JOIN pages p ON p.id = b.page_id
   SET b.items = REPLACE(b.items, 'iscriversi con l autorizzazione', 'iscriversi con l''autorizzazione')
 WHERE p.slug = 'diventa-socio';

UPDATE page_blocks b JOIN pages p ON p.id = b.page_id
   SET b.items = REPLACE(b.items, 'arrivano da fuori citta e', 'arrivano da fuori città e')
 WHERE p.slug = 'diventa-socio';

UPDATE page_blocks b JOIN pages p ON p.id = b.page_id
   SET b.items = REPLACE(b.items, 'Si, segue la stagione sportiva', 'Sì, segue la stagione sportiva')
 WHERE p.slug = 'diventa-socio';

UPDATE page_blocks b JOIN pages p ON p.id = b.page_id
   SET b.items = REPLACE(b.items, 'a quello che puo e che vuole', 'a quello che può e che vuole')
 WHERE p.slug = 'diventa-socio';

UPDATE page_blocks b JOIN pages p ON p.id = b.page_id
   SET b.items = REPLACE(b.items, 'a tutte le attivita?', 'a tutte le attività?')
 WHERE p.slug = 'diventa-socio';

UPDATE events SET info = REPLACE(info, 'Chi non puo partecipare puo mandare', 'Chi non può partecipare può mandare') WHERE slug = 'riunione-mensile-dei-soci';

UPDATE events SET description = REPLACE(description, 'La cena sociale e uno dei momenti', 'La cena sociale è uno dei momenti') WHERE slug = 'cena-sociale-di-primavera';

UPDATE events SET description = REPLACE(description, 'dieci anni pagano meta', 'dieci anni pagano metà') WHERE slug = 'cena-sociale-di-primavera';

UPDATE events SET info = REPLACE(info, 'entro il giovedi precedente', 'entro il giovedì precedente') WHERE slug = 'cena-sociale-di-primavera';

UPDATE events SET description = REPLACE(description, 'Il banchetto sara davanti', 'Il banchetto sarà davanti') WHERE slug = 'raccolta-alimentare-per-la-mensa-cittadina';

UPDATE events SET description = REPLACE(description, 'settore ospiti non e consentito', 'settore ospiti non è consentito') WHERE slug = 'trasferta-a-bologna';

UPDATE products SET description = REPLACE(description, 'Vestibilita regolare', 'Vestibilità regolare') WHERE slug = 'maglietta-curva-fiesole';

UPDATE products SET description = REPLACE(description, 'Al momento non e disponibile', 'Al momento non è disponibile') WHERE slug = 'maglietta-celebrativa-anniversario';

UPDATE organization_roles SET description = REPLACE(description, 'Cura la contabilita', 'Cura la contabilità') WHERE name = 'Responsabile contabile';

-- @DOWN
--
-- Nessuna: reintrodurre di proposito degli errori di ortografia non ha senso.
-- Per tornare ai testi originali si usa `php scripts/seed.php` su un database
-- vuoto.
