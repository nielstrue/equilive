# Equilive – statistik for danske ridestævner

PHP + MySQL-webapplikation der indlæser DRF-udtræk (CSV) og laver statistik
for stævner, klasser og officials. Bygget til lokal WAMP i et virtuelt
directory og klar til senere hosting.

## Funktioner

- Import af `officials_2026.csv` (semikolon-separeret, uden header) til en
  normaliseret MySQL-database - via upload, eller med et klik hentet direkte
  fra `api.equilive.dk` (siden "Equipe official liste" under Import).
- **Genindlæsning uden dubletter**: samme fil kan indlæses ugentligt – nye
  rækker tilføjes, eksisterende opdateres (upsert på naturlige nøgler).
- Beregnede felter:
  - **Stævneniveau** = højeste klasseniveau + flag for om stævnet også har
    klasser på lavere niveau (fx et C-stævne med D-klasser).
  - **Stilspringning** (Ja/Nej) = om klassenavnet indeholder "S2".
- Officials-statistik: antal stævner, klasser, roller, startende ryttere,
  niveauer og DRF-distrikt(er) pr. official. Filtrér på navn, år, distrikt og
  disciplin (kan alle vælges med flere værdier ad gangen, som en OR).
- **Rollekatalog** (siden "Roller"): hver rolle (fx "dressage_judge") knyttes til
  én eller flere discipliner, eller markeres som gældende alle discipliner (fx
  en steward). Bruges til disciplin-filteret på Officials-statistik.
- Stævneoversigt med filtrering på disciplin og niveau + detaljeside med klasser.
- Klub-oversigt med distrikt og antal stævner (filtrér på navn og år, år kan
  vælges med flere værdier ad gangen), og en detaljeside pr. klub med hvilke
  officials klubben har brugt ved sine stævner.
- Høstning af DRF's officials- og klublister, så `officials.drf_listed` og
  `clubs.distrikt` kan holdes ajour mod Dansk Ride Forbunds egne data.
- Pr.-stævne høstning af klassedetaljer (hest/pony, sværhedsgrad) fra DRF's
  stævneresultat-side, udløst af en knap på stævnets side.
- **Login og rollebaseret adgang**: alle sider kræver login; import af data
  (CSV-upload, DRF-høstning) samt dublet-fletning kræver desuden admin-rollen.
- **Alias-historik for officials**: når to officials flettes (fx efter et
  navneskift som "Jens Hansen" → "Jens Fidipus Hansen"), gemmes det gamle navn
  som alias, så både fremtidig CSV-import og DRF-matchning stadig genkender
  personen under begge navne.
- **Status på officials** (aktiv/kun E-niveau/ikke aktiv), redigeres fra
  officialens side. Officials-statistik viser status som badge og har et
  status-filter (samme afkrydsningsdropdown som år/distrikt/disciplin/type),
  som udgangspunkt kun sat til "Aktiv".
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

4. **Opret en bruger** (se "Login og adgang" nedenfor), log ind, gå til
   **Import** og upload din CSV (eller indlæs fra sti). Se derefter
   **Officials** og **Stævner**.

## Login og adgang (brugere/roller)

Alle sider kræver login. Der er to roller:
- **user** – kan se alle sider (officials, klubber, stævner, DRF-afstemning m.m.).
- **admin** – som `user`, men kan derudover tilgå **Import** (CSV-upload samt
  DRF-høstning af officials/klubber). Import-linket i menuen vises kun for admins.

### Opgradér en eksisterende database
```
mysql -u root equilive < sql\migrate_add_user_roles.sql
```
Tilføjer `users.role` og gør den ældste bruger til admin. Nye installationer
får kolonnen automatisk fra `sql/schema.sql`.

### Opret/opdater en bruger
Der er ingen selvbetjent registrering – brugere oprettes fra kommandolinjen:
```
php cli\create_user.php dommer@klub.dk "Jane Dommer" "MinKode123!" user
php cli\create_user.php admin@klub.dk "Admin Adminsen" "MinKode123!" admin
```
Køres den igen med samme email, opdateres navn/adgangskode/rolle i stedet for
at oprette en dublet. Roller kan også ændres direkte i databasen:
```sql
UPDATE users SET role = 'admin' WHERE email = '...';
```

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

### Hent CSV'en automatisk fra api.equilive.dk

Under **Import** henter knappen "Hent og indlæs nyeste CSV" filen direkte fra
`csv_url` (config.php), gemmer den som `default_csv` (typisk
`data/officials_2026.csv`) og importerer den med det samme - uden manuel
download/upload. Kræver at serveren har adgang til internettet via curl
(eller `allow_url_fopen` som fallback).

> Får du en fejl om et manglende CA-certifikat ("unable to get local issuer
> certificate")? Så mangler PHP's `curl.cainfo` at pege på en gyldig
> cert-bundle. På WAMP ligger der typisk en færdig fil i `C:\wamp64\cacert.pem`
> - sæt `curl.cainfo = "C:\wamp64\cacert.pem"` i php.ini for den aktive
> PHP-version og genstart Apache.

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

## Rollekatalog (rolle → disciplin)

`assignments.rolle` er fortsat fritekst (så den frie rolle-editor på en klasses
side er upåvirket), men holdes op mod et opslagskatalog i `roles` +
`role_disciplines` (mange-til-mange, da nogle roller bruges på tværs af flere
discipliner). En rolle er enten:
- knyttet til én eller flere specifikke discipliner (fx `dressage_judge` →
  dressage), eller
- markeret `alle_discipliner = 1`, hvis rollen bruges uanset disciplin (fx
  `technical_delegate`, `chief_steward`).

Kataloget administreres på siden **Roller**, som også viser rollenavne der
optræder i tildelinger, men endnu ikke er katalogiseret (fx efter en manuel
rettelse i klasse-visningen), så de nemt kan tilføjes.

Ud over de rigtige stævne-/klassediscipliner (dressage, show_jumping, …)
findes den syntetiske disciplin **`organizer`** – til roller der handler om
at arrangere/lede stævnet frem for en bestemt sportsgren, fx
`competition_president`. Den optræder altid som en vælgbar kolonne/filter,
selvom ingen klasse nogensinde får denne "disciplin".

Disciplin-filteret på **Officials-statistik** bruger dette katalog: en
official matcher en valgt disciplin, hvis blot én af personens roller enten
er tagget til den disciplin eller gælder alle discipliner.

### Opgradér en eksisterende database
```
mysql -u root equilive < sql\migrate_add_role_disciplines.sql
```
Opretter tabellerne, katalogiserer alle roller der allerede bruges, og sætter
et best-effort startgæt (baseret på rollenavnet) som kan rettes under
**Roller**. Nye installationer får tabellerne automatisk fra `sql/schema.sql`
(uden startgættet).

## Dubletter og alias-historik for officials

Samme person kan optræde som flere `officials`-rækker, typisk fordi navnet er
stavet forskelligt i kildedata, eller fordi personen reelt har **skiftet
navn** (fx "Jens Hansen" → "Jens Fidipus Hansen"). Siden **Dubletter**
(admin-only, i topmenuen) foreslår kandidatpar ud fra navnelighed, og
**Flet officials** (`officials_merge.php`) lægger to poster sammen: alle
tildelinger flyttes til den bevarede official, og kildens post slettes.

Ved fletning gemmes det navn der forsvinder (og evt. dets egne tidligere
aliaser) automatisk i `official_aliases`, knyttet til den bevarede official.
Det betyder:
- **CSV-import** genkender navnet som samme person fremover i stedet for at
  oprette en ny official, hvis navnet dukker op igen i en senere fil.
- **DRF-matchning** (`officials.drf_listed` m.m.) matcher også på tidligere
  navne, så en person der er registreret hos DRF under det gamle navn stadig
  kobles korrekt.

Tidligere navne vises på officialens egen side. Aliaser løses altid til
den bevarede official; findes samme navn allerede som alias for en anden
official (fx efter en tidligere fejlagtig fletning), overskrives det.

### Opgradér en eksisterende database
```
mysql -u root equilive < sql\migrate_add_official_aliases.sql
```
Nye installationer får tabellen automatisk fra `sql/schema.sql`.

### Knyt et umatchet DRF-navn til en official
På siden **DRF-liste** kan admins (kun) knytte hvert navn under "DRF-navne
uden match i dine data" direkte til en official:
- **Tomt felt** → opretter en helt ny official med DRF-navnet.
- **Vælg en eksisterende official** (søgbart tekstfelt) → officialen omdøbes
  til DRF-navnet, og det hidtidige navn gemmes automatisk som alias (samme
  mekanisme som ved fletning). Praktisk når en official har fået nyt navn
  hos DRF, men endnu ikke har haft en rolle registreret under det –
  stævnehistorikken følger med uændret, da det er samme officials-post.

## Status på officials (aktiv/ikke aktiv/kun E-niveau/FEI official)

Officials stopper med jævne mellemrum – alder, sygdom eller dødsfald – uden at
forsvinde fra historikken. Der findes desuden uuddannede klubdommere, der kun
må dømme stævner på E-niveau, samt FEI-officials der ikke kan findes på DRF's
liste. `officials.status` sættes manuelt fra officialens egen side og har fire
værdier:
- `aktiv` – normal, fuldt uddannet official.
- `kun_e_niveau` – må kun dømme E-niveau (uuddannet klubdommer).
- `fei_official` – FEI-official der ikke kan matches på DRF-listen.
- `ikke_aktiv` – stoppet, af enhver årsag.

Status bruges til:
- **Officials-statistik** har et status-filter (samme afkrydsningsdropdown
  som år/distrikt/disciplin/type). Uden nogen valgt status i URL'en (fx
  første besøg) vises som udgangspunkt kun `aktiv` – vil du se "Kun
  E-niveau", "FEI official" og/eller "Ikke aktiv" også, skal de vælges
  eksplicit i dropdownen (checkboxes kan ikke skelne "intet valgt" fra
  "aldrig rørt"). Status vises som badge i listen.
- **Flet officials** viser altid alle officials uanset status, så en ikke
  længere aktiv persons dubletter stadig kan ryddes op.

CSV-import (Equipe) sætter automatisk status tilbage til `aktiv`, hvis en
official der er markeret `ikke_aktiv` dukker op i en ny fil (fx efter en
pause). `kun_e_niveau` og `fei_official` er bevidste klassifikationer og
røres ikke af importen.

### Opgradér en eksisterende database
```
mysql -u root equilive < sql\migrate_add_official_status.sql
```
Har du allerede kørt en tidligere udgave af migreringen, så kør i stedet den
relevante opfølgende fil:
```
mysql -u root equilive < sql\migrate_simplify_official_status.sql        :: fra aktiv/stoppet/afdoed
mysql -u root equilive < sql\migrate_add_official_e_niveau_status.sql    :: fra aktiv/ikke_aktiv
mysql -u root equilive < sql\migrate_add_fei_official_status.sql         :: tilføjer fei_official
```
Nye installationer får kolonnen automatisk fra `sql/schema.sql`.

### Find officials der kun har dømt E-niveau
`sql/find_e_niveau_officials.sql` indeholder en kandidatliste (officials hvis
tildelinger *udelukkende* er på E-niveau-klasser) samt en `UPDATE` der sætter
`status = 'kun_e_niveau'` for dem. Kør sektionerne enkeltvis og tjek
kandidatlisten, før du kører selve `UPDATE`'en – den kører ikke automatisk.

## Dommerkrav (springning)

Siden `dommerkrav.php` viser om springdommere (DRF-typerne "Springdommer -
D/C/B/A") opfylder DRF's aktivitetskrav for at opretholde niveauet, ud fra
dømte stævner (rollen `show_jumping_judge`) registreret i Equilive
(`Stats::springdommerKravStatus()`).

Kravteksten er i sig selv flertydig ("X stævner pr. år ... over en 2-årig
periode"), så fortolkningen er lagt fast eksplicit i koden:
- Totalkravene for Springdommer C/B/A (fx "mindst 8 stævner pr. år ... over
  en periode på 2 år") vurderes FØRST i vinduet [forrige år, indeværende år]
  med de oprindelige krav (X×2, uændret). Er kravet ikke opfyldt dér, vises
  et opmærksomhedsflag ("Opmærksom") i stedet for et hårdt "Nej", fordi
  indeværende års sæson ofte ikke er slut endnu. Samtidig tjekkes om kravet
  var opfyldt i de 2 hele foregående år alene (uden indeværende år) - er det
  tilfældet, vises også "Opfyldt tidligere", så det er synligt at niveauet
  senest var dokumenteret opretholdt. Uden dette ville stort set alle
  springdommere vise "opfylder ikke" i starten af et nyt kalenderår.
- Springdommer-B's C-stævne-krav er eksplicit mærket "(pr. år)" i kravteksten
  og skal derfor opfyldes i BEGGE år i det vindue der evalueres, individuelt.
- Springdommer-D's årlige krav (min. 4 D-stævner) anses for opfyldt hvis
  enten indeværende år eller forrige hele kalenderår opfylder det for sig.
- Et stævnes niveau er stævnets samlede (højeste) niveau (`shows.top_code`),
  ikke nødvendigvis niveauet på den konkrete klasse der blev dømt.
- Springdommer-D's alternative vej ("assistent til C-stævner") indgår ikke,
  da kun rollen `show_jumping_judge` tælles med.
- Deltagelse i DRF's refleksionsdage (krav: mindst hvert 3. år) indgår slet
  ikke – der findes ingen data om dette i Equilive, og skal tjekkes manuelt.

## Sådan udvides appen

Nye use cases tilføjes typisk ved at:
1. Skrive en ny metode i `inc/Stats.php` (queries er samlet her).
2. Oprette en ny side i roden der kalder metoden og bruger `render_header/footer`.
Kernen (Database, Importer, Levels) kan genbruges uændret.

## Filer

```
equilive/
├─ index.php            Forside/overblik
├─ login.php            Log ind
├─ logout.php           Log ud
├─ import.php           Upload + kør import (kræver admin-rolle)
├─ officials.php        Officials-statistik (liste)
├─ official.php         Official – detalje
├─ roles.php            Rollekatalog (rolle → disciplin)
├─ clubs.php            Klub-oversigt (distrikt, antal stævner)
├─ club.php             Klub – detalje med officials brugt
├─ shows.php            Stævneoversigt
├─ show.php             Stævne – detalje med klasser
├─ config.example.php   Konfigurationsskabelon
├─ inc/                 Kerne: Database, Importer, Levels, Stats, layout, bootstrap,
│                       DrfImporter (officials), DrfClubImporter (klubber),
│                       ShowDetailImporter (klassedetaljer pr. stævne),
│                       OfficialMerger (flet/omdøb), DrfOfficialLinker (knyt DRF-navn)
├─ cli/import.php              CLI-importer til planlagt kørsel
├─ cli/import_drf_clubs.php    CLI-høstning af DRF's klubliste (distrikt)
├─ cli/create_user.php         Opret/opdater login-bruger + rolle
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
Enten i browseren under **Import** (live-knap, eller upload en gemt HTML-fil),
eller via CLI/cmd:
```
php cli\import_drf_clubs.php                          :: live fra rideforbund.dk
php cli\import_drf_clubs.php --file sti\til\gemt.html  :: fra gemt HTML
```
`drf_clubs.cmd` kører live-høstningen og kan planlægges ved lejlighed, ligesom
`drf.cmd`.

## Klassedetaljer (hest/pony, sværhedsgrad)

Hver klasse i en officials-CSV mangler to ting som kun findes på selve
stævneresultatet: om klassen er for **hest**, **pony** eller **begge**, og
klassens **sværhedsgrad** ("svh 0-5"). Det høstes fra DRF's stævneresultat-side
via `shows.prop` som `EventId` (fx `Prop00056809` →
`.../staevneresultat?EventId=Prop00056809`).

I modsat af officials-/klublisterne er dette IKKE et fuldt snapshot – der
høstes ét stævne ad gangen, udløst af knappen **"Hent klassedetaljer fra DRF"**
på stævnets side (`show.php`). Resultatet gemmes på `classes.hest_pony`,
`classes.svaerhedsgrad` og `classes.drf_class_id` (DRF's eget klasse-id, til
robust genhøstning), og `shows.detail_harvested_at` opdateres så du kan se
hvornår et stævne sidst blev hentet.

Matchning sker på klassenummerets numeriske præfiks: er en klasse splittet
lokalt i flere runder (fx `15B`/`15 C`), får begge den samme sværhedsgrad/
hest-pony fra DRF's ene klasse "15.".

### Opgradér databasen
```
mysql -u root equilive < sql\migrate_add_class_details.sql
```

### Kør høstningen
Klik knappen på et stævnes side (`show.php?id=...`). Kræver at serveren har
adgang til internettet (curl) og et gyldigt Prop-id – stævner med manglende
Prop (markeret "?") kan ikke slås op.
