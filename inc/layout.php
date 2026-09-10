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
        'clubs'     => 'Klubber',
        'shows'     => 'Stævner',
        'officials_uden_rolle' => 'Uden rolle',
        'status_krav' => 'Opretholdelse af status',
    ];
    if ($user && has_permission('ADMIN_ACCESS', $user)) {
        $nav['drf'] = 'DRF-liste';
        $nav['fei'] = 'FEI-liste';
        $nav['roles'] = 'Roller';
        $nav['riders'] = 'Ryttere'; // TODO: flyt tilbage til alle roller når rytterfunktionen er færdigudviklet
        $nav['officials_duplicates'] = 'Dubletter';
        $nav['officials_merge'] = 'Flet officials';
        $nav['clubs_merge'] = 'Flet klubber';
        $nav['import'] = 'Import';
        $nav['import_riders'] = 'Ryttere-import';
        $nav['warnings'] = 'Advarsler';
        $nav['deleted_assignments'] = 'Slettede tildelinger';
    }
    if ($user && has_permission('USER_READ', $user)) {
        $nav['brugere'] = 'Brugere';
    }
    ?><!DOCTYPE html>
<html lang="da">
<head>
    <?php html_head($title); ?>
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= h(url('')) ?>"><?= render_logo(30) ?> Equilive</a>
    <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Åbn menu"
            aria-expanded="false" aria-controls="topbar-collapse">
        <span class="nav-toggle-bar"></span><span class="nav-toggle-bar"></span><span class="nav-toggle-bar"></span>
    </button>
    <div class="topbar-collapse" id="topbar-collapse">
        <nav>
            <?php foreach ($nav as $slug => $label): ?>
                <a href="<?= h(url($slug === '' ? '' : $slug . '.php')) ?>"
                   class="<?= $active === $slug ? 'active' : '' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($user): ?>
            <span class="topbar-user">
                <?= h($user['name']) ?> <span class="badge badge-muted"><?= h(role_label($user['role'])) ?></span>
                · <a href="<?= h(url('skift_kodeord.php')) ?>">Skift kodeord</a>
                · <a href="<?= h(url('mfa_setup.php')) ?>">To-faktor login</a>
                · <a href="<?= h(url('logout.php')) ?>">Log ud</a>
            </span>
        <?php endif; ?>
    </div>
</header>
<main class="container">
<?php
}

function render_footer(): void
{
    ?>
</main>
<footer class="foot">
    <span>Equilive · statistik for <strong class="foot-highlight">officials</strong> ved danske ridestævner</span>
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

/** Lille badge for et stævnes livscyklus-status (aktiv/udelukket). */
function show_status_badge(string $status): string
{
    $map = [
        'aktiv'     => ['Aktiv', 'badge-drf'],
        'udelukket' => ['Udelukket', 'badge-warn'],
    ];
    [$label, $class] = $map[$status] ?? [$status, 'badge-muted'];
    return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

/** Lille badge for en klubs livscyklus-status (aktiv/ophoert). */
function club_status_badge(string $status): string
{
    $map = [
        'aktiv'   => ['Aktiv', 'badge-drf'],
        'ophoert' => ['Ophørt', 'badge-muted'],
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

/**
 * "Smart" tilbage-navigation: en detaljeside (show.php, official.php osv.)
 * kan tilgås fra flere forskellige lister/andre detaljesider (fx både
 * officials.php og en officials "Stævner"-tabel kan føre til show.php).
 * Uden dette ville "← ..."-linket øverst altid pege samme sted hen, uanset
 * hvor brugeren reelt kom fra.
 *
 * here_path()/from_params() bruges på AFSENDER-siden til at maerke et
 * udgaaende link med hvor det blev klikket fra; back_link() bruges paa
 * MODTAGER-siden til at vise "← Label" hen til det, hvis sat - ellers
 * sidens normale standard-forælder (fx "Alle officials").
 *
 * url() gør from-værdien sikker at bruge direkte (kan aldrig blive en
 * ekstern/absolut URL, uanset hvad $_GET['from'] indeholder) - se url()'s
 * ltrim-håndtering af ledende skråstreger.
 */

/** Denne sides egen sti+query (uden base_path) - saa man kan lede tilbage hertil, filtre i URL'en inkluderet. */
function here_path(): string
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    return $script . ($qs !== '' ? '?' . $qs : '');
}

/** Query-parametre der maerker et udgaaende link med hvor brugeren kom fra - tilføj til enden af et href med '&'. */
function from_params(string $label): string
{
    return 'from=' . rawurlencode(here_path()) . '&from_label=' . rawurlencode($label);
}

/**
 * Videregiver et evt. indkommende from/from_label uændret - til links på
 * SAMME side der ikke selv skal ændre "hvor kom du fra" (fx "vis alle år"),
 * saa det ikke går tabt undervejs. Returnerer '' hvis intet from er sat.
 * Tilføj til enden af et href med '&' (ligesom from_params()).
 */
function carry_from(): string
{
    $from = trim($_GET['from'] ?? '');
    if ($from === '') {
        return '';
    }
    return 'from=' . rawurlencode($from) . '&from_label=' . rawurlencode(trim($_GET['from_label'] ?? ''));
}

/** Renderer "← Label"-tilbage-linket øverst på en detaljeside. */
function back_link(string $defaultUrl, string $defaultLabel): void
{
    $from  = trim($_GET['from'] ?? '');
    $label = trim($_GET['from_label'] ?? '');
    $href  = $from !== '' ? url($from) : $defaultUrl;
    $text  = $from !== '' && $label !== '' ? $label : $defaultLabel;
    echo '<p><a href="' . h($href) . '">← ' . h($text) . '</a></p>';
}
