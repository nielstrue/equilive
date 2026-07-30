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
];
