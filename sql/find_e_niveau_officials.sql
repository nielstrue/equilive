-- ============================================================
--  Find officials der udelukkende har dømt/officieret på E-niveau
--  (niveau_slug = 'club') og saet status = 'kun_e_niveau' for dem.
--
--  Kør migreringen sql/migrate_add_official_e_niveau_status.sql (eller
--  den opdaterede sql/migrate_add_official_status.sql for nye databaser)
--  FØR denne fil.
--
--  Kør sektionerne enkeltvis og tjek kandidatlisten, før du kører UPDATE'en
--  nederst - den er bevidst IKKE automatisk kørt af denne fil.
-- ============================================================
USE equilive;

-- ---------- 1) Kandidatliste (kun review, ændrer intet) ----------
-- Officials med status 'aktiv', hvor ALLE tildelinger er på E-niveau-klasser
-- (niveau_slug = 'club'). En official uden nogen tildelinger overhovedet
-- tælles ikke med (de har jo aldrig dømt noget).
SELECT o.id, o.navn,
       COUNT(*)                                            AS antal_tildelinger,
       GROUP_CONCAT(DISTINCT a.rolle ORDER BY a.rolle SEPARATOR ', ') AS roller
FROM officials o
JOIN assignments a ON a.official_id = o.id
JOIN classes c      ON c.id = a.class_id
WHERE o.status = 'aktiv'
GROUP BY o.id, o.navn
HAVING SUM(c.niveau_slug <> 'club' OR c.niveau_slug IS NULL) = 0
ORDER BY o.navn;

-- ---------- 2) (Valgfrit) Begræns til rene dommerroller ----------
-- Ovenstående tæller ALLE roller (også fx banedesign, steward). Vil du kun
-- medregne roller der reelt er dømmerroller, så tilføj denne linje til
-- WHERE-betingelsen i JOIN'en ovenfor (eller kør denne udgave i stedet):
--
-- ... JOIN assignments a ON a.official_id = o.id AND a.rolle LIKE '%judge%' ...
--
-- (fanger dressage_judge, show_jumping_judge, judge, chief_judge,
--  foreign_judge, style_judge, water_jump_judge - men IKKE fx
--  ground_jury_president/-member, som heller ikke bogstaveligt indeholder
--  "judge". Tilpas listen selv, hvis den skal med.)

-- ---------- 3) Saet status - kør foerst naar kandidatlisten er godkendt ----------
UPDATE officials o
SET o.status = 'kun_e_niveau'
WHERE o.status = 'aktiv'
  AND o.id IN (
      SELECT official_id FROM (
          SELECT a.official_id
          FROM assignments a
          JOIN classes c ON c.id = a.class_id
          GROUP BY a.official_id
          HAVING SUM(c.niveau_slug <> 'club' OR c.niveau_slug IS NULL) = 0
      ) kandidater
  );
