# Equilive – statistik for danske ridestævner

PHP + MySQL-webapplikation der indlæser DRF-udtræk (CSV) og laver statistik
for stævner, klasser og officials. Bygget til lokal WAMP i et virtuelt
directory og klar til senere hosting.

## Funktioner

- Import af `officials_2026.csv` (semikolon-separeret, uden header) til en
  normaliseret MySQL-database.
- **Genindlæsning uden dubletter**: samme fil kan indlæses ugentligt – nye
  rækker tilføjes, eksisterende opdateres (upsert på naturlige nøgler).
- Beregnede felter:
  - **Stævneniveau** = højeste klasseniveau + flag for om stævnet også har
    klasser på lavere niveau (fx et C-stævne med D-klasser).
  - **Stilspringning** (Ja/Nej) = om klassenavnet indeholder "S2".
- Officials-statistik: antal stævner, klasser, roller, startende ryttere,
  niveauer og DRF-distrikt(er) pr. official. Filtrér på navn, år og distrikt
  (år og distrikt kan vælges med flere værdier ad gangen, som en OR).
- Stævneoversigt med filtrering på disciplin og niveau + detaljeside med klasser.
- Klub-oversigt med distrikt og antal stævner (filtrér på navn og år, år kan
  vælges med flere værdier ad gangen), og en detaljeside pr. klub med hvilke
  officials klubben har brugt ved sine stævner.
- Høstning af DRF's officials- og klublister, så `officials.drf_listed` og
  `clubs.distrikt` kan holdes ajour mod Dansk Ride Forbunds egne data.
- CLI-importer til planlagt (fx ugentlig) kørsel.

## Niveau-mapping

| CSV (Niveau)   | Kode | Rang |
|----------------|------|------|
| club           | E    | 1    |
| local          | D    | 2    |
| regional       | C    | 3    |
| national       | B    | 4    |
| elite          | A    | 5    |
| international   | FEI  | 6    |

Stævnets niveau = højeste rang blandt dets klasser; `har lavere` sættes hvis
mindst én klasse ligger under den højeste rang.

## Opsætning på WAMP

1. **Læg mappen** i webroddens område, fx `C:\wamp64\www\equilive`.
   (Eller opret et virtuelt directory der peger på mappen.)

2. **Opret databasen** – åbn phpMyAdmin og importér `sql/schema.sql`,
   eller kør fra kommandolinjen:
   ```
   C:\wamp64\bin\mysql\mysql8.x\bin\mysql.exe -u root < sql\schema.sql
   ```

3. **Konfigurér** – kopiér `config.example.php` til `config.php` og ret
   `db`-oplysninger og `base_path`:
   - Virtuelt directory `http://localhost/equilive/` → `'base_path' => '/equilive'`
   - Kører i roden → `'base_path' => ''`

4. **Åbn appen** i browseren, gå til **Import** og upload din CSV
   (eller indlæs fra sti). Se derefter **Officials** og **Stævner**.

## Ugentlig import (automatisk)

Læg den nye CSV i `data/officials_2026.csv` og kør:
```
php cli\import.php
```
eller med eksplicit sti:
```
php cli\import.php C:\sti\til\officials_2026.csv
```
Planlæg den evt. via Windows Task Scheduler ugentligt.

## Datamodel (normaliseret)

```
clubs (klubber)
shows (stævner) ──< classes (klasser) ──< assignments (roller) >── officials
levels (opslag: niveau → kode/rang)
imports (importlog)
```

- `shows` bruger en naturlig nøglehash (`prop|forkort|dato|klub`), fordi
  kildens `Prop` kan være `UNKNOWN` for flere forskellige stævner. Stævner
  med manglende Prop markeres med et lille "?".
- `classes` identificeres af (stævne, klassenr, klassenavn).
- `assignments` har unik nøgle (klasse, official, rolle) → ingen dubletter.

## Sådan udvides appen

Nye use cases tilføjes typisk ved at:
1. Skrive en ny metode i `inc/Stats.php` (queries er samlet her).
2. Oprette en ny side i roden der kalder metoden og bruger `render_header/footer`.
Kernen (Database, Importer, Levels) kan genbruges uændret.

## Filer

```
equilive/
├─ index.php            Forside/overblik
├─ import.php           Upload + kør import
├─ officials.php        Officials-statistik (liste)
├─ official.php         Official – detalje
├─ clubs.php            Klub-oversigt (distrikt, antal stævner)
├─ club.php             Klub – detalje med officials brugt
├─ shows.php            Stævneoversigt
├─ show.php             Stævne – detalje med klasser
├─ config.example.php   Konfigurationsskabelon
├─ inc/                 Kerne: Database, Importer, Levels, Stats, layout, bootstrap,
│                       DrfImporter (officials), DrfClubImporter (klubber)
├─ cli/import.php              CLI-importer til planlagt kørsel
├─ cli/import_drf_clubs.php    CLI-høstning af DRF's klubliste (distrikt)
├─ sql/schema.sql       Databaseskema
├─ assets/style.css     Styling
└─ data/                CSV-fil(er)
```

## Planlagt import

### Lokalt på WAMP (nu) – via `import.cmd`

`import.cmd` finder automatisk WAMP's PHP og kører `cli/import.php`. Ret evt.
`APP`-stien øverst i filen. Kør den ved dobbeltklik, eller planlæg ugentligt.

Opret opgaven fra en **Administrator-cmd**:
```
schtasks /Create /TN "Equilive ugentlig import" /TR "C:\wamp64\www\equilive\import.cmd" /SC WEEKLY /D MON /ST 06:00 /RL LIMITED /F
```
Output logges til `data\import.log`. Vil du hente en frisk CSV fra API'et før
importen, så fjern `REM ` foran `curl`-linjen i `import.cmd`.

### Hosted (senere) – via cron

Samme CLI-script bruges. Eksempel: hver mandag kl. 06:00:
```
0 6 * * 1 /usr/bin/php /var/www/equilive/cli/import.php /var/www/equilive/data/officials_2026.csv >> /var/www/equilive/data/import.log 2>&1
```
Vil du også hente CSV'en først:
```
0 6 * * 1 curl -fsS -o /var/www/equilive/data/officials_2026.csv https://api.equilive.dk/DRF/officials_2026.csv && /usr/bin/php /var/www/equilive/cli/import.php /var/www/equilive/data/officials_2026.csv >> /var/www/equilive/data/import.log 2>&1
```

## Årstal og flere år

Hver CSV-fil dækker ét år, og **årstallet udledes automatisk af filnavnet**
(`officials_2025.csv` → 2025); ellers bruges årstallet fra stævnets dato.
Året gemmes på hvert stævne (`shows.aar`) og bruges til filtrering.

### Opgradér en eksisterende database

Har du allerede importeret 2026-data, så kør migreringen én gang (tilføjer
`aar`-kolonnen og udfylder den fra datoerne):
```
mysql -u root equilive < sql\migrate_add_year.sql
```
Nye installationer får kolonnen automatisk fra `sql/schema.sql`.

### Indlæs tidligere år (backfill)

Læg alle filerne i `data\` (fx `officials_2019.csv` … `officials_2026.csv`) og
kør enten `import_all.cmd` (dobbeltklik) eller CLI'en mod hele mappen:
```
php cli\import.php C:\Users\niels\dev\equilive\data
```
Filerne indlæses ældste år først, og året sættes pr. fil. Kan gentages uden
dubletter.

### Filtrér på år

På **Officials**- og **Stævner**-siderne er der en "År"-dropdown. Vælger du
fx 2024, viser statistikken kun det år (antal stævner, klasser, ryttere og
niveauer tælles kun for det valgte år). Forsiden viser desuden antal stævner
og klasser pr. år.

På **Officials**-siden er "År"- og "Distrikt"-filtrene afkrydsningsdropdowns:
du kan vælge flere år (fx 2025 **og** 2026) og/eller flere distrikter ad
gangen, og resultatet er en OR – dvs. officials der matcher mindst ét af de
valgte år/distrikter.

## DRF officials-liste (find-dommer)

Applikationen kan høste Dansk Ride Forbunds officielle officials-liste fra
find-dommer-siden og holde din `officials`-tabel op mod den.

Hvad du får:
- En markering `officials.drf_listed` (fundet på DRF-listen: ja/nej) – vises som
  en ✓ i officials-oversigten.
- En ny tabel `drf_officials` med **type** (fx "Springdommer - A"),
  **distrikt** (fx "Distrikt 03"), afledt **kategori** samt by/kontakt. En person
  kan optræde flere gange (flere typer/distrikter) – officials-oversigten viser
  alle en officials distrikter i én kolonne og kan filtreres på dem.
- En **afstemningsrapport** (siden "DRF-liste") der viser hvor navnene ikke
  matcher – både DRF-navne uden match i dine data og dine officials der ikke er
  på DRF-listen. Det er dét, der hjælper med at rette stavefejl og forbedre
  datakvaliteten.

Matchning sker på normaliseret navn (små bogstaver, ét mellemrum). I den
aktuelle test matchede 443 af 564 stævne-officials (79%) mod DRF-listen.

### Opgradér databasen
```
mysql -u root equilive < sql\migrate_add_drf.sql
```

### Kør høstningen
Enten i browseren under **Import → DRF officials-liste**, eller via CLI/cmd:
```
php cli\import_drf.php            :: live fra rideforbund.dk
php cli\import_drf.php --file     :: fra gemt HTML (data\Find Dommer.html)
```
`drf.cmd` kører live-høstningen og kan planlægges ved lejlighed. Hver kørsel er
et fuldt snapshot (listen erstattes), så den er altid ajour.

> Bemærk: find-dommer-siden loader tabellen server-side, så live-hentning med
> curl bør virke. Kan din server ikke nå nettet, så gem siden som HTML i `data\`
> og brug `--file`.

## DRF klubliste (find-klubber)

Applikationen kan på samme måde høste Dansk Ride Forbunds klubliste fra
find-klubber-siden og bruge den til at udfylde, hvilket distrikt hver af dine
klubber (`clubs`) hører under.

Hvad du får:
- En ny kolonne `clubs.distrikt` (fx "Distrikt 03"), udfyldt for de klubber der
  matches mod DRF-listen.
- En ny tabel `drf_clubs` med det fulde snapshot (navn, forkortelse/ClubId,
  distrikt, adresse, url) og hvilken lokal klub den blev matchet til (hvis
  nogen). Samme fuld-snapshot-tilgang som officials-listen: hver kørsel
  erstatter hele `drf_clubs`.

Matchning sker primært på klubbens forkortelse (`clubs.forkort`/`club_key`,
som er det samme "ClubId" DRF selv bruger i deres URL'er) og ellers på
normaliseret navn. I den aktuelle test matchede 310 af 435 lokale klubber
(71%) – resten er typisk klubber der endnu ikke har arrangeret et stævne i
dine data, eller specialposter på DRF-listen (stævnearrangører, forbund mm.)
som ikke er "rigtige" klubber.

### Opgradér databasen
```
mysql -u root equilive < sql\migrate_add_club_distrikt.sql
```

### Kør høstningen
Enten i browseren under **Import → DRF klubliste**, eller via CLI/cmd:
```
php cli\import_drf_clubs.php            :: live fra rideforbund.dk
php cli\import_drf_clubs.php --file     :: fra gemt HTML (data\Find Klubber.html)
```
`drf_clubs.cmd` kører live-høstningen og kan planlægges ved lejlighed, ligesom
`drf.cmd`.
