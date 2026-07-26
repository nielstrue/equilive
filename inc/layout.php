<?php
defined('APP') or die('Direkte adgang ikke tilladt');

function render_header(string $title, string $active = ''): void
{
    $nav = [
        ''          => 'Forside',
        'officials' => 'Officials',
        'clubs'     => 'Klubber',
        'shows'     => 'Stævner',
        'drf'       => 'DRF-liste',
        'import'    => 'Import',
    ];
    ?><!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> · Equilive</title>
    <link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
    <script src="<?= h(url('assets/app.js')) ?>" defer></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= h(url('')) ?>">🐴 Equilive</a>
    <nav>
        <?php foreach ($nav as $slug => $label): ?>
            <a href="<?= h(url($slug === '' ? '' : $slug . '.php')) ?>"
               class="<?= $active === $slug ? 'active' : '' ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>
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
