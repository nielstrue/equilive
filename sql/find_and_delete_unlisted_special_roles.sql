-- ============================================================
--  Find og (valgfrit) slet tildelinger med rollerne "announcer",
--  "competition_president" eller "show_director", hvor officialen HVERKEN
--  er fundet på DRF-listen (officials.drf_listed) ELLER FEI-listen
--  (officials.fei_listed) - dvs. en umatchet person i en af disse tre
--  administrative roller. Kun klasser af typen "show_jumping".
--
--  Kør sektion 1 og tjek listen, før du kører DELETE'en i sektion 2 - den
--  er bevidst IKKE automatisk kørt af denne fil. Ret evt. rollelisten
--  herunder.
--
--  Matcher kun a.rolle (den viste/normaliserede rolle), ikke orig_rolle.
--
--  Kolonnen collate'es eksplicit til utf8mb4_danish_ci for at undgå
--  kollisions-fejl (#1267/#1253) mellem tabellens collation og
--  forbindelsens standard-collation for strenge/variabler.
-- ============================================================
USE equilive;

-- ---------- 1) Kandidatliste (kun review, ændrer intet) ----------
SELECT a.id                AS assignment_id,
       o.id                AS official_id,
       o.navn              AS official,
       o.drf_listed,
       o.fei_listed,
       a.rolle,
       cl.klassenavn,
       cl.klassenr,
       s.prop,
       s.dato
FROM assignments a
JOIN officials o ON o.id = a.official_id
JOIN classes cl  ON cl.id = a.class_id
JOIN shows s     ON s.id = cl.show_id
WHERE a.rolle COLLATE utf8mb4_danish_ci IN ('announcer', 'competition_president', 'show_director', 'controller', 'supervisor', 'steward', 'chief_steward', 'chief_steward_assistant', 'foreign_veterinary_delegate', 'press_manager', 'result_manager', 'show_director')
  AND o.drf_listed = 1
  AND o.fei_listed = 0
  AND cl.disciplin = 'show_jumping'
ORDER BY s.prop, o.navn, s.dato, cl.klassenr + 0;

-- Vil du blot se antallet i stedet for hele listen:
-- SELECT COUNT(*) FROM assignments a
-- JOIN officials o ON o.id = a.official_id
-- JOIN classes cl  ON cl.id = a.class_id
-- WHERE a.rolle COLLATE utf8mb4_danish_ci IN ('announcer', 'competition_president', 'show_director', 'controller', 'supervisor', 'steward', 'chief_steward', 'chief_steward_assistant', 'foreign_veterinary_delegate', 'press_manager', 'result_manager', 'show_director')
--   AND o.drf_listed = 1
--   AND o.fei_listed = 0
--   AND cl.disciplin = 'show_jumping';

-- ---------- 2) Slet - kør foerst naar listen ovenfor er godkendt ----------
-- DELETE a FROM assignments a
-- JOIN officials o ON o.id = a.official_id
-- JOIN classes cl  ON cl.id = a.class_id
-- WHERE a.rolle COLLATE utf8mb4_danish_ci IN ('announcer', 'competition_president', 'show_director', 'controller', 'supervisor', 'steward', 'chief_steward', 'chief_steward_assistant', 'foreign_veterinary_delegate', 'press_manager', 'result_manager', 'show_director')
--   AND o.drf_listed = 1
--   AND o.fei_listed = 0
--   AND cl.disciplin = 'show_jumping';
