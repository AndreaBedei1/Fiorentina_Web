-- Il testo di apertura dei contatti non promette piu una sede sempre aperta.
--
-- Diceva che "il modo piu rapido per parlarci e passare in sede negli orari di
-- apertura": non e vero, e chi si presentasse in sede contando su quella frase
-- non troverebbe nessuno. Al suo posto le tre strade che funzionano davvero:
-- il modulo, l'email e i profili social.
--
-- L'aggiornamento tocca solo il testo originale: se qualcuno lo ha gia
-- riscritto dal pannello, la sua versione resta.

UPDATE pages
   SET intro = 'Scrivici con il modulo qui sotto, per email o dai nostri profili social: rispondiamo appena possibile.'
 WHERE slug = 'contatti'
   AND intro LIKE 'Il modo pi_ rapido per parlarci e passare in sede%';

-- @DOWN

UPDATE pages
   SET intro = 'Il modo più rapido per parlarci e passare in sede negli orari di apertura. Altrimenti usa il modulo qui sotto o scrivici via email.'
 WHERE slug = 'contatti'
   AND intro LIKE 'Scrivici con il modulo qui sotto%';
