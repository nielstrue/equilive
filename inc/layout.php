<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Equilive-logoet: en grøn badge med en hesteskol i midten. Bruges i topbar,
 * på login-siden og som favicon (assets/favicon.svg har samme motiv).
 */
function render_logo(int $size = 32): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 100 100" '
        . 'xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
        . '<rect x="4" y="4" width="92" height="92" rx="24" fill="#1f6f54"/>'
        . '<path d="M35,72 L35,45 A15,15 0 0 1 65,45 L65,72" fill="none" '
        . 'stroke="#ffffff" stroke-width="12" stroke-linecap="round"/>'
        . '</svg>';
}

/** Cache-busting version til assets: aendrer sig automatisk naar filen redigeres,
 *  saa browseren ikke bliver ved med at vise en gammel cachet udgave af CSS/JS. */
function asset_version(string $relPath): string
{
    $full = APP_ROOT . '/' . ltrim($relPath, '/');
    $mtime = is_file($full) ? filemtime($full) : time();
    return url($relPath) . '?v=' . $mtime;
}

function html_head(string $title): void
{
    ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> · Equilive</title>
    <link rel="icon" type="image/svg+xml" href="<?= h(asset_version('assets/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= h(asset_version('assets/style.css')) ?>">
    <script src="<?= h(asset_version('assets/app.js')) ?>" defer></script>
    <?php
}

function render_header(string $title, string $active = ''): void
{
    $user = current_user();
    $nav = [
        ''          => 'Forside',
        'officials' => 'Officials',
        'dommerkrav' => 'Dommerkrav',
        'roles'     => 'Roller',
        'clubs'     => 'Klubber',
        'shows'     => 'Stævner',
        'drf'       => 'DRF-liste',
    ];
    if (($user['role'] ?? '') === 'admin') {
        $nav['officials_duplicates'] = 'Dubletter';
        $nav['officials_merge'] = 'Flet officials';
        $nav['import'] = 'Import';
    }
    ?><!DOCTYPE html>
<html lang="da">
<head>
    <?php html_head($title); ?>
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= h(url('')) ?>"><?= render_logo(30) ?> Equilive</a>
    <nav>
        <?php foreach ($nav as $slug => $label): ?>
            <a href="<?= h(url($slug === '' ? '' : $slug . '.php')) ?>"
               class="<?= $active === $slug ? 'active' : '' ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($user): ?>
        <span class="topbar-user">
            <?= h($user['name']) ?><?= $user['role'] === 'admin' ? ' <span class="badge badge-drf">admin</span>' : '' ?>
            · <a href="<?= h(url('logout.php')) ?>">Log ud</a>
        </span>
    <?php endif; ?>
</header>
<main class="container">
<?php
}

function render_footer(): void
{
    ?>
</main>
<footer class="foot">
    <span>Equilive · statistik for danske ridestævner</span>
</footer>
</body>
</html>
<?php
}

/** Lille badge for stævnets niveau, fx "C" med "+lavere". */
function level_badge(?string $code, $hasLower = false): string
{
    if ($code === null || $code === '') return '<span class="badge badge-muted">–</span>';
    $extra = $hasLower ? ' <span class="badge-sub">+lavere</span>' : '';
    return '<span class="badge badge-lvl badge-' . h($code) . '">' . h($code) . '</span>' . $extra;
}

/** Lille badge for en officials status (aktiv/ikke_aktiv/kun_e_niveau/fei_official). */
function official_status_badge(string $status): string
{
    $map = [
        'aktiv'        => ['Aktiv', 'badge-drf'],
        'ikke_aktiv'   => ['Ikke aktiv', 'badge-muted'],
        'kun_e_niveau' => ['Kun E-niveau', 'badge-lvl badge-E'],
        'fei_official' => ['FEI official', 'badge-lvl badge-FEI'],
    ];
    [$label, $class] = $map[$status] ?? [$status, 'badge-muted'];
    return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

/**
 * Dropdown med afkrydsningsfelter til multi-valg filtre (OR-semantik).
 * $options er en liste af value=>label (rækkefølgen bevares, fx år nyeste først).
 * $selected er de valgte values. Visuelt matcher knappen de øvrige <select>-felter.
 */
function checkbox_dropdown(string $name, string $allLabel, array $options, array $selected): void
{
    $count = count($selected);
    $triggerLabel = $count > 0 ? $allLabel . ' (' . $count . ')' : $allLabel;
    ?>
    <div class="dropdown-check">
        <button type="button" class="dropdown-trigger"><?= h($triggerLabel) ?></button>
        <div class="dropdown-check-panel">
            <?php foreach ($options as $value => $optionLabel): ?>
                <label>
                    <input type="checkbox" name="<?= h($name) ?>[]" value="<?= h((string)$value) ?>"
                        <?= in_array((string)$value, $selected, true) ? 'checked' : '' ?>>
                    <?= h((string)$optionLabel) ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function ja_nej($v): string
{
    return $v ? '<span class="ja">Ja</span>' : '<span class="nej">Nej</span>';
}

function dk_date(?string $iso): string
{
    if (!$iso) return '–';
    $t = strtotime($iso);
    return $t ? date('d-m-Y', $t) : h($iso);
}
