<?php
/**
 * Equilive - konfiguration (EKSEMPEL)
 * -----------------------------------
 * Kopiér denne fil til "config.php" og ret værdierne til dit miljø.
 * config.php er med i .gitignore, så dine adgangskoder ikke havner i git.
 */
return [
    // --- Database (MySQL / MariaDB i WAMP) ---
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'equilive',
        'user'    => 'root',
        'pass'    => '',            // WAMP's root har typisk tomt password lokalt
        'charset' => 'utf8mb4',
    ],

    // Basis-URL-sti til appen. Ved virtuelt directory i WAMP, fx
    // http://localhost/equilive/  -> sæt til '/equilive'.
    // Kør appen i webroddens rod -> sæt til ''.
    'base_path' => '/equilive',

    // Standardsti til CSV ved CLI-import (kan overstyres som argument).
    'default_csv' => __DIR__ . '/data/officials_2026.csv',

    // Vis PHP-fejl i browseren (slå fra i produktion).
    'debug' => true,

    // DRF officials-liste ("find-dommer") - bruges til at markere/afstemme officials.
    'drf_url'  => 'https://rideforbund.dk/officials/springning/find-dommer?pagenum=1&pagesize=999999',
    'drf_file' => __DIR__ . '/data/Find Dommer.html',

    // DRF klubliste ("find-klubber") - bruges til at udfylde clubs.distrikt.
    'drf_clubs_url'  => 'https://rideforbund.dk/go/klubber/find-klubber?pagenum=1&pagesize=999999',
    'drf_clubs_file' => __DIR__ . '/data/Find Klubber.html',
];
