-- Gli album non hanno piu un "tipo".
--
-- Le sei caselle (stadio, trasferte, eventi, raduni, storico, altro) erano un
-- filtro in piu nella galleria e una scelta in piu da fare a ogni album. Con
-- qualche decina di album non aiutavano a trovare niente: chi cerca una
-- fotografia la cerca per anno o la riconosce dalla copertina, non pensa
-- "questa era un raduno".
--
-- Il filtro per anno resta: quello e il modo in cui si cerca davvero.

ALTER TABLE albums DROP COLUMN category;

-- @DOWN

ALTER TABLE albums
    ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'altro' AFTER description;
