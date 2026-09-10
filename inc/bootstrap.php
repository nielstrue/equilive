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

    // --- Sikkerhedsheadere (svar på security-scan: CSP/clickjacking/MIME-sniffing/serverversion) ---
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // script-src/style-src tillader 'unsafe-inline' pga. udbredt brug af
    // onclick/onsubmit-attributter og style="" i templates (ingen ekstern kilde
    // er tilladt uanset, saa det beskytter stadig mod cross-domain script-/
    // indholdsinjektion - det er kun beskyttelsen mod INLINE-script-XSS der er
    // svagere, hvilket er lavere risiko her da appen ikke render'er brugerinput
    // som HTML nogen steder).
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self'; font-src 'self'; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

    // --- CSRF-beskyttelse: alle POST-requests skal bære et gyldigt token ---
    // (se csrf_token()/csrf_valid() nedenfor). Centraliseret her saa hver enkelt
    // side ikke selv skal huske at tjekke det - skal blot tilføje csrf_field()
    // inde i sine "form method=post"-formularer.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;max-width:32rem;margin:3rem auto">'
            . '<h1>Sessionen kunne ikke bekræftes</h1>'
            . '<p>Genindlæs siden og prøv igen (kan ske hvis siden har stået åben længe, eller er åbnet i flere faner).</p>'
            . '</div>';
        exit;
    }
}

/** Den loggede ind bruger (id, name, email, role), eller null. */
function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * Rollebaseret adgang (RBAC): fire faste roller. Hvilke rettigheder hver
 * rolle har, ligger i databasen (tabellen equilive_role_permissions) og
 * redigeres i GUI'et under "Brugere" → "Rolle-rettigheder" - IKKE hardcodet
 * her, saa en admin kan justere det uden en kodeændring. Rollerne og
 * rettighederne (navnene) er derimod stadig faste, se
 * known_roles()/known_permissions().
 *
 * NB: users-tabellen deles med en anden applikation (Prizesim) i produktion.
 * Kun users.role (rollenavnet) er fælles - selve rolle→rettighed-mappingen
 * ligger i equilive_role_permissions, som er Equilive-alene, saa den ikke
 * kan kollidere med noget Prizesim har eller senere tilføjer. Tilsvarende
 * ligger Equilive-specifik brugertilstand (tvunget kodeordsskift, login-log)
 * i equilive_user_state i stedet for som kolonner direkte på users - se
 * ensure_equilive_user_state() nedenfor.
 */
function known_roles(): array {
    return ['admin', 'editor', 'user', 'readonly'];
}

function known_permissions(): array {
    return ['USER_READ', 'USER_WRITE', 'USER_DELETE', 'REPORT_VIEW', 'ADMIN_ACCESS'];
}

/** Danske labels for rollerne, til visning i UI'et. */
function role_label(string $role): string {
    $labels = ['admin' => 'Admin', 'editor' => 'Editor', 'user' => 'Bruger', 'readonly' => 'Kun læsning'];
    return $labels[$role] ?? $role;
}

/** Korte danske forklaringer af rettighederne, til visning i UI'et. */
function permission_label(string $permission): string {
    $labels = [
        'USER_READ'    => 'Se brugere',
        'USER_WRITE'   => 'Oprette/redigere brugere',
        'USER_DELETE'  => 'Slette brugere',
        'REPORT_VIEW'  => 'Se rapporter/statistik',
        'ADMIN_ACCESS' => 'Fuld admin-adgang (Import, fletning m.m.)',
    ];
    return $labels[$permission] ?? $permission;
}

/** Rolle → rettigheder, hentet fra role_permissions-tabellen og cachet for
 *  resten af request'en. Cachen ryddes af invalidate_role_permissions_cache()
 *  når en admin gemmer ændringer, saa resten af SAMME request (fx menuen i
 *  render_header) også ser de nye rettigheder med det samme. */
function role_permissions(): array {
    if (!isset($GLOBALS['__role_permissions'])) {
        $map = array_fill_keys(known_roles(), []);
        foreach (db()->all('SELECT role, permission FROM equilive_role_permissions') as $row) {
            if (isset($map[$row['role']])) {
                $map[$row['role']][] = $row['permission'];
            }
        }
        $GLOBALS['__role_permissions'] = $map;
    }
    return $GLOBALS['__role_permissions'];
}

function invalidate_role_permissions_cache(): void {
    unset($GLOBALS['__role_permissions']);
}

function has_permission(string $permission, ?array $user = null): bool {
    $user = $user ?? current_user();
    $role = $user['role'] ?? '';
    return in_array($permission, role_permissions()[$role] ?? [], true);
}

/** Kræver at brugeren er logget ind - sender til login.php ellers.
 *  Tjekker samtidig frisk status i databasen (ikke kun sessionen), saa en
 *  deaktiveret bruger logges ud med det samme, en rolleændring slår igennem
 *  uden gen-login, og et tvunget kodeordsskift fanges før resten af appen
 *  tilgås. skift_kodeord.php/logout.php er undtaget, ellers kunne en bruger
 *  med tvunget skift aldrig nå frem til siden der skal løse det. */
function require_login(): void {
    if (current_user() === null) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . url('login.php') . ($next !== '' ? '?next=' . urlencode($next) : ''));
        exit;
    }

    // Session-timeout ved inaktivitet - logger automatisk ud efter
    // SESSION_IDLE_MINUTES uden aktivitet, uanset hvor længe browserfanen har
    // stået åben (cookien i sig selv udløber først ved browser-luk, se
    // session_set_cookie_params() ovenfor).
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_MINUTES * 60) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . url('login.php') . '?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();

    $row = db()->one(
        'SELECT u.role, u.is_active, COALESCE(s.must_change_password, 0) AS must_change_password
         FROM users u
         LEFT JOIN equilive_user_state s ON s.user_id = u.id
         WHERE u.id = ?',
        [current_user()['id']]
    );
    if (!$row || !$row['is_active']) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . url('login.php'));
        exit;
    }
    $_SESSION['user']['role'] = $row['role'];

    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($row['must_change_password'] && !in_array($script, ['skift_kodeord.php', 'logout.php'], true)) {
        header('Location: ' . url('skift_kodeord.php'));
        exit;
    }
}

/** Kræver en given rettighed - viser "adgang nægtet" ellers. */
function require_permission(string $permission): void {
    require_login();
    if (!has_permission($permission)) {
        require_once __DIR__ . '/layout.php';
        http_response_code(403);
        render_header('Adgang nægtet', '');
        echo '<div class="notice error">Denne side kræver rettigheden "' . h($permission) . '".</div>';
        render_footer();
        exit;
    }
}

/** Kræver admin-rollen (fx til import af data) - viser "adgang nægtet" ellers. */
function require_admin(): void {
    require_permission('ADMIN_ACCESS');
}

// ---------------- Login-sikkerhed: rate limiting, konto-lockout, session-timeout ----------------

const LOGIN_IP_WINDOW_MINUTES    = 15; // tidsvindue for IP-rate-limiting
const LOGIN_IP_MAX_ATTEMPTS      = 20; // maks. loginforsøg fra samme IP (alle emails) i vinduet
const LOGIN_ACCOUNT_MAX_FAILED   = 5;  // maks. forkerte forsøg i træk for én konto før lockout
const LOGIN_ACCOUNT_LOCK_MINUTES = 15; // hvor længe en konto er låst efter lockout
const SESSION_IDLE_MINUTES       = 30; // session logges automatisk ud efter så mange minutters inaktivitet

/** Klientens IP-adresse (til rate limiting). REMOTE_ADDR er den eneste kilde
 *  vi kan stole på uden en kendt/betroet proxy foran appen (en X-Forwarded-For
 *  header kan forfalskes af klienten selv). */
function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Har denne IP lavet for mange loginforsøg for nyligt? Tælles uafhængigt af
 *  om email/kodeord var korrekt, og på tværs af emails - beskytter mod brute
 *  force/credential stuffing fordelt over mange konti fra samme afsender. */
function login_ip_rate_limited(string $ip): bool {
    $count = (int)db()->scalar(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > NOW() - INTERVAL ' . LOGIN_IP_WINDOW_MINUTES . ' MINUTE',
        [$ip]
    );
    return $count >= LOGIN_IP_MAX_ATTEMPTS;
}

/** Logger ét loginforsøg (til IP-rate-limiting ovenfor) - kaldes for både
 *  vellykkede og forkerte forsøg. */
function log_login_attempt(string $ip, string $email, bool $success): void {
    db()->run(
        'INSERT INTO login_attempts (ip, email, success) VALUES (?, ?, ?)',
        [$ip, $email !== '' ? $email : null, $success ? 1 : 0]
    );
}

/** Er kontoen p.t. låst pga. for mange forkerte forsøg i træk? Returnerer
 *  tidspunktet låsen slutter, eller null hvis kontoen ikke er låst. */
function account_locked_until(int $userId): ?string {
    $row = db()->one(
        'SELECT locked_until FROM equilive_user_state WHERE user_id = ? AND locked_until > NOW()',
        [$userId]
    );
    return $row['locked_until'] ?? null;
}

/** Registrerer et forkert loginforsøg for kontoen - låser den i
 *  LOGIN_ACCOUNT_LOCK_MINUTES minutter, naar LOGIN_ACCOUNT_MAX_FAILED
 *  forkerte forsøg i træk er naaet. */
function register_failed_login(int $userId): void {
    ensure_equilive_user_state($userId);
    db()->run(
        'UPDATE equilive_user_state
         SET failed_login_count = failed_login_count + 1,
             locked_until = IF(failed_login_count + 1 >= ?, NOW() + INTERVAL ? MINUTE, locked_until)
         WHERE user_id = ?',
        [LOGIN_ACCOUNT_MAX_FAILED, LOGIN_ACCOUNT_LOCK_MINUTES, $userId]
    );
}

/** Nulstiller forkerte forsøg/lockout for kontoen - kaldes ved et vellykket login. */
function reset_failed_login(int $userId): void {
    db()->run('UPDATE equilive_user_state SET failed_login_count = 0, locked_until = NULL WHERE user_id = ?', [$userId]);
}

/** Færdiggør et login (sætter den fulde session) - fælles for login.php (uden MFA)
 *  og mfa_verify.php (efter en gyldig TOTP-/genoprettelses-/email-kode). Kaldes
 *  KUN når email+kodeord (og evt. MFA) allerede er bekræftet. */
function complete_login(array $user): void {
    ensure_equilive_user_state((int)$user['id']);
    reset_failed_login((int)$user['id']);
    db()->run('UPDATE equilive_user_state SET last_login_at = NOW(), login_count = login_count + 1 WHERE user_id = ?', [$user['id']]);
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'    => (int)$user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['mfa_pending']);
}

/** Kodeordspolitik: mindst 8 tegn, mindst ét stort bogstav, mindst ét tal.
 *  Returnerer en fejlbesked, eller null hvis kodeordet er godkendt. */
function validate_password(string $password): ?string {
    if (mb_strlen($password) < 8) {
        return 'Kodeordet skal være mindst 8 tegn.';
    }
    if (!preg_match('/[A-ZÆØÅ]/u', $password)) {
        return 'Kodeordet skal indeholde mindst ét stort bogstav.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Kodeordet skal indeholde mindst ét tal.';
    }
    return null;
}

/** Groft styrke-skøn (0-4) til en visuel indikator - selve kravet er validate_password(). */
function password_strength(string $password): int {
    $score = 0;
    if (mb_strlen($password) >= 8)  $score++;
    if (mb_strlen($password) >= 12) $score++;
    $classes = 0;
    foreach (['/[a-zæøå]/u', '/[A-ZÆØÅ]/u', '/[0-9]/', '/[^a-zA-ZæøåÆØÅ0-9]/u'] as $pattern) {
        if (preg_match($pattern, $password)) $classes++;
    }
    if ($classes >= 3) $score++;
    if ($classes >= 4) $score++;
    return min($score, 4);
}

/** Foreslår et 1. gangs-kodeord der er nemt at huske: to ridesports-ord + tal.
 *  Opfylder altid validate_password() (stort bogstav fra hvert ord, cifre til sidst). */
function suggest_password(): string {
    $ord = [
        'Hest', 'Sadel', 'Trense', 'Stald', 'Ridebane', 'Spring', 'Galop', 'Hoppe',
        'Ponny', 'Manege', 'Dressur', 'Travet', 'Bidsel', 'Stigboejle', 'Ridehjelm', 'Hovslag',
    ];
    $w1 = $ord[array_rand($ord)];
    do {
        $w2 = $ord[array_rand($ord)];
    } while ($w2 === $w1);
    return $w1 . $w2 . random_int(10, 99);
}

/** Sikrer at brugeren har en række i equilive_user_state (default-værdier),
 *  saa efterfølgende UPDATE'er (login-log, tvunget kodeordsskift) altid
 *  rammer en eksisterende række - ogsaa for en bruger der oprindeligt kun
 *  er kendt af Prizesim og aldrig før har rørt ved Equilive. Idempotent. */
function ensure_equilive_user_state(int $userId): void {
    db()->run('INSERT IGNORE INTO equilive_user_state (user_id) VALUES (?)', [$userId]);
}

function db(): Database {
    static $db = null;
    if ($db === null) {
        $db = new Database($GLOBALS['config']['db']);
    }
    return $db;
}

/** CSRF-token for den aktuelle session - ét token pr. session (ikke pr. request),
 *  saa flere åbne faner/formularer virker samtidig. Genereres første gang det
 *  efterspørges (fungerer derfor også på login.php, før nogen er logget ind). */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_valid(?string $token): bool {
    return is_string($token) && $token !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/** Skjult inputfelt til en "form method=post" - indsæt inde i formularen. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
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

/** Maskerer en email til visning (fx "j***@example.com") - bruges når man skal
 *  vise HVOR en engangskode er sendt hen, uden at afsløre hele adressen. */
function mask_email(string $email): string {
    $at = strpos($email, '@');
    if ($at === false || $at === 0) {
        return $email;
    }
    return substr($email, 0, 1) . str_repeat('*', max($at - 1, 1)) . substr($email, $at);
}
