-- L'ordine del direttivo si sposta con due frecce.
--
-- Fino a ieri le persone comparivano nell'ordine in cui erano state
-- inserite: bastava a chi scriveva l'organigramma dall'inizio, ma non a chi
-- doveva spostare qualcuno in mezzo mesi dopo - l'unica strada era
-- cancellare e riscrivere.
--
-- Torna quindi una colonna di ordinamento, ma nessuno la vede: non e un
-- numero da compilare in un modulo, e la posizione nella lista. Le frecce su
-- e giu scambiano due righe vicine, e a ogni spostamento la numerazione
-- viene riscritta da capo, cosi resta sempre 0, 1, 2... senza buchi.
--
-- Il valore di partenza e l'id: e esattamente l'ordine che l'elenco aveva
-- fino a un momento fa.

ALTER TABLE organization_members ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER role;

UPDATE organization_members SET sort_order = id;

CREATE INDEX idx_organization_members_order ON organization_members (sort_order);

-- @DOWN

DROP INDEX idx_organization_members_order ON organization_members;

ALTER TABLE organization_members DROP COLUMN sort_order;
