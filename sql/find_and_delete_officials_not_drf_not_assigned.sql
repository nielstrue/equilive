-- ============================================================
--  Find og (valgfrit) slet "forældreløse" officials: hverken fundet paa
--  DRF's officielle liste (drf_listed = 0) og ikke tilknyttet noget AKTIVT
--  stævne - en tildeling til et stævne med status = 'udelukket' tæller
--  IKKE som en tilknytning (se sql/schema.sql: 'udelukket' holder stævnet
--  helt ude af statistikker/opgørelser, fx et fejlagtigt importeret
--  udenlandsk stævne) - typisk "døde" rækker fra en tidligere
--  fejlmatchning/import, som aldrig fik en rigtig tildeling.
--
--  Sletning af en officials-række cascader automatisk til official_aliases
--  og assignments (FK ON DELETE CASCADE, se sql/schema.sql) - der er ikke
--  brug for separate DELETE'er til dem. drf_officials/fei_officials sættes
--  til NULL i official_id (FK ON DELETE SET NULL), saa selve DRF/FEI-
--  listerækken bevares.
--
--  Kør sektion 1 og tjek listen, før du kører DELETE'en i sektion 2 - den
--  er bevidst IKKE automatisk kørt af denne fil.
-- ============================================================
USE equilive;

-- ---------- 1) Kandidatliste (kun review, ændrer intet) ----------
SELECT o.id AS official_id,
       o.navn,
       o.status,
       o.drf_listed,
       o.fei_listed,
       (SELECT COUNT(*) FROM official_aliases oa WHERE oa.official_id = o.id) AS antal_aliaser,
       (SELECT COUNT(*) FROM assignments a
            JOIN classes c ON c.id = a.class_id
            JOIN shows   s ON s.id = c.show_id
            WHERE a.official_id = o.id AND s.status = 'udelukket') AS antal_tildelinger_udelukket
FROM officials o
WHERE o.drf_listed = 0
  AND NOT EXISTS (
      SELECT 1 FROM assignments a
      JOIN classes c ON c.id = a.class_id
      JOIN shows   s ON s.id = c.show_id
      WHERE a.official_id = o.id AND s.status = 'aktiv'
  )
ORDER BY o.navn;

-- Vil du blot se antallet i stedet for hele listen:
-- SELECT COUNT(*) FROM officials o
-- WHERE o.drf_listed = 0
--   AND NOT EXISTS (
--       SELECT 1 FROM assignments a
--       JOIN classes c ON c.id = a.class_id
--       JOIN shows   s ON s.id = c.show_id
--       WHERE a.official_id = o.id AND s.status = 'aktiv'
--   );

-- ---------- 2) Slet - kør foerst naar listen ovenfor er godkendt ----------
-- DELETE FROM officials
-- WHERE drf_listed = 0
--   AND NOT EXISTS (
--       SELECT 1 FROM assignments a
--       JOIN classes c ON c.id = a.class_id
--       JOIN shows   s ON s.id = c.show_id
--       WHERE a.official_id = officials.id AND s.status = 'aktiv'
--   );
