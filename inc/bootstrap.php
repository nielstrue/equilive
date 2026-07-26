<?php
/**
 * Equilive - fælles opstart (bootstrap)
 * Inkluderes øverst i alle sider og CLI-scripts.
 */
declare(strict_types=1);

define('APP', true);
define('APP_ROOT', dirname(__DIR__));

// --- Konfiguration ---
$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Mangler config.php - kopiér config.example.php til config.php og ret værdierne.');
}
$config = require $configFile;

if (!empty($config['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

mb_internal_encoding('UTF-8');

// --- Autoload af klasser i inc/ ---
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// --- Global databaseforbindelse ---
$GLOBALS['config'] = $config;

function db(): Database {
    static $db = null;
    if ($db === null) {
        $db = new Database($GLOBALS['config']['db']);
    }
    return $db;
}

/** Byg en URL relativt til appens base_path. */
function url(string $path = ''): string {
    $base = rtrim($GLOBALS['config']['base_path'] ?? '', '/');
    return $base . '/' . ltrim($path, '/');
}

/** Escape til HTML-output. */
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
