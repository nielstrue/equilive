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

// --- Session (login) - ikke relevant for CLI-scripts ---
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim($config['base_path'] ?? '', '/') . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isHttps,
    ]);
    session_start();
}

/** Den loggede ind bruger (id, name, email, role), eller null. */
function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

/** Kræver at brugeren er logget ind - sender til login.php ellers. */
function require_login(): void {
    if (current_user() === null) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . url('login.php') . ($next !== '' ? '?next=' . urlencode($next) : ''));
        exit;
    }
}

/** Kræver admin-rollen (fx til import af data) - viser "adgang nægtet" ellers. */
function require_admin(): void {
    require_login();
    if ((current_user()['role'] ?? 'user') !== 'admin') {
        require_once __DIR__ . '/layout.php';
        http_response_code(403);
        render_header('Adgang nægtet', '');
        echo '<div class="notice error">Denne side kræver admin-rollen.</div>';
        render_footer();
        exit;
    }
}

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
