-- ============================================================
--  Lister alle roller/tildelinger for én official ud fra navn - til
--  fejlsøgning, fx naar en official uventet ikke dukker op i en anden
--  liste (som sql/find_and_delete_officials_not_drf_not_assigned.sql),
--  for at se om hun/han rent faktisk har en eller flere tildelinger.
--
--  Ret @official_navn herunder til det navn du vil undersøge.
--
--  @official_navn CONVERT'es eksplicit til utf8mb4 nedenfor, fordi denne
--  forbindelse tilsyneladende bruger charset 'binary' for brugervariabler
--  (SET @x = '...'), som ikke må COLLATE'es til utf8mb4_danish_ci (fejl
--  #1253) uden først at blive konverteret.
-- ============================================================
USE equilive;

SET @official_navn = 'Anne Cathrine Sellæg';

-- ---------- 1) Selve official-rækken (status, DRF/FEI-match) ----------
SELECT o.id, o.navn, o.status, o.drf_listed, o.fei_listed
FROM officials o
WHERE o.navn = CONVERT(@official_navn USING utf8mb4) COLLATE utf8mb4_danish_ci;

-- ---------- 2) Tidligere navne (alias-historik) - i tilfælde af navneskift ----------
SELECT oa.navn AS tidligere_navn, oa.created_at
FROM official_aliases oa
JOIN officials o ON o.id = oa.official_id
WHERE o.navn = CONVERT(@official_navn USING utf8mb4) COLLATE utf8mb4_danish_ci;

-- ---------- 3) Alle tildelinger (roller) for officialen ----------
SELECT a.id AS assignment_id,
       a.rolle,
       a.orig_rolle,
       a.nummer,
       cl.klassenr,
       cl.klassenavn,
       cl.disciplin,
       s.prop,
       s.dato,
       s.aar,
       s.status AS show_status
FROM assignments a
JOIN officials o ON o.id = a.official_id
JOIN classes cl  ON cl.id = a.class_id
JOIN shows s     ON s.id = cl.show_id
WHERE o.navn = CONVERT(@official_navn USING utf8mb4) COLLATE utf8mb4_danish_ci
ORDER BY s.dato, cl.klassenr + 0;

-- Vil du blot se antallet i stedet for hele listen:
-- SELECT COUNT(*) FROM assignments a
-- JOIN officials o ON o.id = a.official_id
-- WHERE o.navn = CONVERT(@official_navn USING utf8mb4) COLLATE utf8mb4_danish_ci;
