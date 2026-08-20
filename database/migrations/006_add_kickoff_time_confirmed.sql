-- Orario della partita: fissato oppure ancora da definire.
--
-- I fornitori di calendario distinguono due situazioni che a database
-- finivano confuse. Quando la giornata e calendarizzata ma l'orario non e
-- ancora stato deciso dalla lega, l'API restituisce la data con orario
-- 00:00 UTC come segnaposto. Salvandolo cosi com'era, il sito mostrava
-- partite alle 02:00 di notte: un orario inventato, indistinguibile da uno
-- vero per chi legge.
--
-- Con questa colonna il sito puo scrivere "orario da definire", che e
-- l'informazione corretta.
--
-- Le partite gia presenti si considerano confermate: e il caso della
-- stragrande maggioranza, e quelle inserite a mano hanno sempre un orario
-- scelto da una persona.

ALTER TABLE football_matches
    ADD COLUMN kickoff_time_confirmed TINYINT(1) NOT NULL DEFAULT 1 AFTER kickoff_at;

-- Le partite che a database hanno esattamente mezzanotte non hanno un orario
-- vero: e il segnaposto del fornitore, tradotto nel fuso italiano.
UPDATE football_matches
SET kickoff_time_confirmed = 0
WHERE status = 'scheduled'
  AND TIME(kickoff_at) IN ('00:00:00', '01:00:00', '02:00:00');

-- @DOWN

ALTER TABLE football_matches DROP COLUMN kickoff_time_confirmed;
