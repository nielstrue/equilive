-- ============================================================
--  Find og (valgfrit) slet alle klasser med disciplin "score_summary" -
--  en syntetisk opsummeringsraekke fra kilden, ikke en rigtig klasse.
--  Fra og med denne ændring springer Importer disse rækker helt over ved
--  fremtidig import (se inc/Importer.php), men allerede importerede
--  score_summary-klasser skal ryddes manuelt med denne fil én gang.
--
--  Sletning af en classes-række cascader automatisk til assignments og
--  class_riders (FK ON DELETE CASCADE, se sql/schema.sql) - der er ikke
--  brug for separate DELETE'er til dem.
--
--  Kør sektion 1 og tjek listen, før du kører DELETE'en i sektion 2 - den
--  er bevidst IKKE automatisk kørt af denne fil.
--
--  KØR EFTERFØLGENDE: php cli/recompute_show_levels.php - ellers kan
--  stævnernes cachede niveau/disciplin (shows.top_rank/top_slug/top_code/
--  has_lower/disciplin) være forkerte, hvis en score_summary-klasse var talt
--  med i den seneste genberegning.
-- ============================================================
USE equilive;

-- ---------- 1) Kandidatliste (kun review, ændrer intet) ----------
SELECT c.id AS class_id,
       s.prop,
       s.dato,
       c.klassenr,
       c.klassenavn,
       (SELECT COUNT(*) FROM assignments a WHERE a.class_id = c.id)   AS antal_tildelinger,
       (SELECT COUNT(*) FROM class_riders cr WHERE cr.class_id = c.id) AS antal_ryttere
FROM classes c
JOIN shows s ON s.id = c.show_id
WHERE c.disciplin = 'score_summary'
ORDER BY s.dato, s.prop, c.klassenr + 0;

-- Vil du blot se antallet i stedet for hele listen:
-- SELECT COUNT(*) FROM classes WHERE disciplin = 'score_summary';

-- ---------- 2) Slet - kør foerst naar listen ovenfor er godkendt ----------
-- DELETE FROM classes WHERE disciplin = 'score_summary';
