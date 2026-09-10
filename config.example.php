<?php
/**
 * Equilive - konfiguration (EKSEMPEL)
 * -----------------------------------
 * Kopiér denne fil til "config.php" og udfyld adgangskoderne.
 * config.php er med i .gitignore, så adgangskoder ikke havner i git, og bør
 * ligge UDEN for web-roden eller være beskyttet på produktionsserveren.
 *
 * $isLocal detekterer automatisk lokalt (Windows/WAMP) vs. produktion, saa
 * config.php kan deployes uændret til prod uden at skulle rettes til hver gang -
 * ret blot 'dsn'/'user'/'pass' for begge miljøer én gang for alle herunder.
 */
$isLocal = PHP_OS_FAMILY === 'Windows';

return [
    'db' => $isLocal
        ? [ // Lokalt (WAMP)
            'dsn'  => 'mysql:host=127.0.0.1;dbname=equilive;charset=utf8mb4',
            'user' => 'root',
            'pass' => '',           // WAMP's root har typisk tomt password lokalt
        ]
        : [ // Produktion
            'dsn'  => 'mysql:host=localhost;dbname=DIT_DB_NAVN;charset=utf8mb4',
            'user' => 'DIN_DB_BRUGER',
            'pass' => 'DIT_DB_PASSWORD',
        ],

    // Basis-URL-sti til appen. Ret '/equilive' til den undermappe appen reelt
    // ligger i på produktionsserveren (ingen ret nødvendig hvis den ligger i
    // webroddens rod der -> sæt til '').
    'base_path' => $isLocal ? '' : '/equilive',

    // Standardsti til CSV ved CLI-import (kan overstyres som argument).
    // Bruges ogsaa som maalfil naar CSV'en hentes automatisk via csv_url.
    'default_csv' => __DIR__ . '/data/officials_2026.csv',

    // URL til den nyeste officials-CSV - lader appen selv hente og indlæse
    // den (knap under Import), i stedet for manuel upload hver gang.
    'csv_url' => 'https://api.equilive.dk/DRF/officials_2026.csv',

    // Vis PHP-fejl i browseren - kun lokalt, aldrig i produktion.
    'debug' => $isLocal,

    // DRF officials-liste ("find-dommer") - bruges til at markere/afstemme officials.
    // Kan ikke hentes live? Upload en gemt HTML-fil i stedet under Import.
    'drf_url' => 'https://rideforbund.dk/officials/springning/find-dommer?pagenum=1&pagesize=999999',

    // DRF klubliste ("find-klubber") - bruges til at udfylde clubs.distrikt.
    'drf_clubs_url' => 'https://rideforbund.dk/go/klubber/find-klubber?pagenum=1&pagesize=999999',

    // DRF stævneresultat - bruges til at hente klassedetaljer (hest/pony, sværhedsgrad)
    // for ét stævne ad gangen (EventId = shows.prop). Se knappen på et stævnes side.
    'drf_show_url' => 'https://rideforbund.dk/go/resultater-ranglister/staevneresultat',

    // DRF klasseresultat - bruges til at hente ryttere (navn+RiderId) pr. klasse
    // (EventId = shows.prop, SectionId = classes.drf_class_id). Se RiderResultImporter.
    'drf_class_result_url' => 'https://rideforbund.dk/go/resultater-ranglister/staevneresultat/klasseresultat',

    // DRF rytterprofil - bruges til at hente supplerende rytterdata (licens, kategorier)
    // for én rytter ad gangen (RiderId = riders.drf_rider_id). Se RiderDetailImporter.
    'drf_rider_url' => 'https://rideforbund.dk/go/heste-og-ryttere/find-ryttere/vis-rytter',

    // Delt hemmelighed for URL-trigget cron (se cron_rider_details.php) - bruges når
    // hosting ikke har shell/crontab-adgang, kun en "cron via URL"-funktion i panelet.
    // Skift til en tilfældig streng (fx via `php -r "echo bin2hex(random_bytes(24));"`),
    // og hold den hemmelig - alle med denne værdi kan udløse batch-jobbet.
    'cron_secret' => 'RET_MIG_TIL_EN_TILFAELDIG_HEMMELIGHED',

    // Fuld URL til appen (med skema+host) - bruges i adgangsmailen (se inc/Mailer.php),
    // hvor base_path alene ikke er nok.
    'app_url' => $isLocal ? 'http://localhost/equilive' : 'https://DIT-DOMAENE/equilive',

    // SMTP-oplysninger til adgangsmails (brugere.php - knappen "Send adgangsmail").
    // Sendes ALDRIG automatisk, kun naar en admin selv trykker på knappen.
    // Ret host/port/smtp_secure til det din hosting/mailudbyder oplyser under
    // "Udgående indstillinger (SMTP)" - fx port 465+'ssl' eller port 587+'tls'.
    'mail' => [
        'host'        => 'DIN_SMTP_SERVER',
        'port'        => 465,
        'smtp_secure' => 'ssl', // 'ssl' (port 465) eller 'tls' (STARTTLS, port 587)
        'username'    => 'DIN_SMTP_BRUGER',
        'password'    => 'DIT_SMTP_PASSWORD',
        'from_email'  => 'DIN_AFSENDER@DIT-DOMAENE',
        'from_name'   => 'Equilive',
    ],

    // Saet til true for at faa den fulde SMTP-samtale skrevet til PHP's error_log ved
    // fejlsøgning (uafhaengig af 'debug' herover, som ogsaa viser PHP-fejl i browseren).
    // Husk at saette den tilbage til false igen bagefter.
    'mail_debug' => false,

    // Krypteringsnøgle til TOTP-hemmeligheder (to-faktor login, se inc/Mfa.php).
    // Generér én unik værdi pr. installation med:
    //   php -r "echo sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), SODIUM_BASE64_VARIANT_ORIGINAL), PHP_EOL;"
    // Skift ALDRIG denne værdi når først nogen har aktiveret to-faktor login -
    // så kan deres gemte hemmelighed ikke længere dekrypteres, og de låses ude.
    'mfa_encryption_key' => 'RET_MIG_TIL_EN_GENERERET_SODIUM_NOEGLE',
];
