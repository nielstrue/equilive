-- ============================================================
--  Find og (valgfrit) slet tildelinger med en bestemt rolle, fx "announcer".
--  @official_navn er valgfri i sektion 1 (NULL = alle officials med rollen),
--  men PÅKRÆVET i sektion 2 - DELETE'en sletter kun tildelinger for den
--  navngivne official, ikke alle med rollen.
--
--  Kør sektionerne enkeltvis og tjek listen i sektion 1, før du kører
--  DELETE'en i sektion 2 - den er bevidst IKKE automatisk kørt af denne fil.
--  Ret @role og @official_navn herunder.
--
--  Alle brugervariabler CONVERT'es eksplicit til utf8mb4 nedenfor, fordi
--  denne forbindelse tilsyneladende bruger charset 'binary' for
--  brugervariabler (SET @x = '...'), som ikke må COLLATE'es til
--  utf8mb4_danish_ci (fejl #1253) uden først at blive konverteret.
-- ============================================================
USE equilive;

SET @role = 'announcer';
SET @official_navn = NULL; -- NULL viser/rammer i stedet alle officials med rollen

-- ---------- 1) Kandidatliste (kun review, ændrer intet) ----------
-- Matcher baade rolle (den viste rolle, se Importer::normalizeRolle) og
-- orig_rolle (den raa CSV-rolle) - for de fleste roller er de identiske,
-- men fx 'judge'/'chief_judge' kan vaere normaliseret til noget andet.
SELECT a.id                AS assignment_id,
       o.navn              AS official,
       a.rolle,
       a.orig_rolle,
       a.nummer,
       cl.klassenavn,
       cl.klassenr,
       s.prop,
       s.dato
FROM assignments a
JOIN officials o ON o.id = a.official_id
JOIN classes cl  ON cl.id = a.class_id
JOIN shows s     ON s.id = cl.show_id
WHERE (a.rolle = CONVERT(@role USING utf8mb4) COLLATE utf8mb4_danish_ci
       OR a.orig_rolle = CONVERT(@role USING utf8mb4) COLLATE utf8mb4_danish_ci)
  AND (@official_navn IS NULL OR o.navn = CONVERT(@official_navn USING utf8mb4) COLLATE utf8mb4_danish_ci)
ORDER BY s.dato, cl.klassenr + 0, o.navn;

-- Vil du blot se antallet i stedet for hele listen:
-- SELECT COUNT(*) FROM assignments a
-- JOIN officials o ON o.id = a.official_id
-- WHERE (a.rolle = CONVERT(@role USING utf8mb4) COLLATE utf8mb4_danish_ci
--        OR a.orig_rolle = CONVERT(@role USING utf8mb4) COLLATE utf8mb4_danish_ci)
--   AND (@official_navn IS NULL OR o.navn = CONVERT(@official_navn USING utf8mb4) COLLATE utf8mb4_danish_ci);

-- ---------- 2) Slet - kun for @official_navn, kør foerst naar listen ovenfor er godkendt ----------
-- Saet @official_navn til et konkret navn ovenfor foerst - denne DELETE
-- er bevidst skrevet saa den ikke rammer noget, hvis @official_navn er NULL.
-- SET @role = 'announcer';
-- SET @official_navn = NULL; -- NULL viser/rammer i stedet alle officials med rollen

-- DELETE a FROM assignments a
-- JOIN officials o ON o.id = a.official_id
-- WHERE (a.rolle = CONVERT(@role USING utf8mb4) COLLATE utf8mb4_danish_ci
--        OR a.orig_rolle = CONVERT(@role USING utf8mb4) COLLATE utf8mb4_danish_ci)
--   AND o.navn = CONVERT(@official_navn USING utf8mb4) COLLATE utf8mb4_danish_ci;
