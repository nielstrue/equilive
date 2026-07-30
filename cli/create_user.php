<?php
/**
 * Opret eller opdater en bruger (til login) fra kommandolinjen.
 * Der er ingen selvbetjent registrering i appen - brugere oprettes/administreres
 * her eller direkte i databasen.
 *
 * Brug:
 *   php cli/create_user.php <email> <navn> <adgangskode> [role]
 *
 * Eksempel:
 *   php cli/create_user.php dommer@klub.dk "Jane Dommer" "MinKode123!" user
 *   php cli/create_user.php admin@klub.dk "Admin Adminsen" "MinKode123!" admin
 *
 * Findes brugeren allerede (samme email), opdateres navn/adgangskode/rolle.
 */
require __DIR__ . '/../inc/bootstrap.php';

[$script, $email, $name, $password, $role] = array_pad($argv, 5, null);
$role = $role ?? 'user';

if (!$email || !$name || !$password) {
    fwrite(STDERR, "Brug: php cli/create_user.php <email> <navn> <adgangskode> [role]\n");
    exit(1);
}
if (!in_array($role, ['user', 'admin'], true)) {
    fwrite(STDERR, "Ukendt rolle '$role' - skal være 'user' eller 'admin'.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$existing = db()->scalar('SELECT id FROM users WHERE email = ?', [$email]);
if ($existing !== false) {
    db()->run(
        'UPDATE users SET name = ?, password_hash = ?, role = ?, is_active = 1 WHERE id = ?',
        [$name, $hash, $role, $existing]
    );
    echo "Opdaterede bruger #$existing ($email), rolle=$role.\n";
} else {
    db()->run(
        'INSERT INTO users (name, address, email, password_hash, role, is_active, activated_at)
         VALUES (?, ?, ?, ?, ?, 1, NOW())',
        [$name, '', $email, $hash, $role]
    );
    echo "Oprettede ny bruger ($email), rolle=$role.\n";
}
