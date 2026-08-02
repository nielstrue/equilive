-- ============================================================
--  Migrering: assignments.orig_rolle
--  Bevarer den oprindeligt importerede rolletekst adskilt fra den
--  visningsrolle en admin evt. har rettet manuelt i class.php, saa en
--  genindlæsning fra equilive.dk (Importer::upsertAssignment) genkender
--  rækken paa dens oprindelige rolle og lader den manuelt rettede rolle
--  være i fred i stedet for at oprette en ny (duplikeret) tildeling med
--  den oprindelige/forkerte rolle.
--  Koeres EN gang paa en eksisterende Equilive-database.
--
--  OBS: for rækker der allerede er rettet manuelt FØR denne migrering,
--  kendes den oprindelige CSV-rolle ikke længere - orig_rolle sættes her
--  til den nuværende (rettede) rolle. Det betyder at næste genindlæsning
--  kan indsætte én enkelt duplikeret række med den oprindelige CSV-rolle
--  for disse (allerede rettede) tildelinger; derefter er de beskyttet
--  fremover. Nye rettelser efter denne migrering er beskyttet med det
--  samme.
-- ============================================================
USE equilive;

ALTER TABLE assignments ADD COLUMN orig_rolle VARCHAR(60) NULL AFTER rolle;
UPDATE assignments SET orig_rolle = rolle WHERE orig_rolle IS NULL;
ALTER TABLE assignments MODIFY COLUMN orig_rolle VARCHAR(60) NOT NULL;
ALTER TABLE assignments ADD INDEX idx_assign_orig (class_id, official_id, orig_rolle);
