// Afkrydsningsdropdowns i filterformularer (se checkbox_dropdown() i inc/layout.php).
document.addEventListener('click', function (e) {
    var trigger = e.target.closest('.dropdown-trigger');
    var dropdown = e.target.closest('.dropdown-check');

    if (trigger) {
        var parent = trigger.closest('.dropdown-check');
        var wasOpen = parent.classList.contains('open');
        document.querySelectorAll('.dropdown-check.open').forEach(function (d) {
            d.classList.remove('open');
        });
        if (!wasOpen) parent.classList.add('open');
        return;
    }

    if (!dropdown) {
        document.querySelectorAll('.dropdown-check.open').forEach(function (d) {
            d.classList.remove('open');
        });
    }
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.dropdown-check.open').forEach(function (d) {
            d.classList.remove('open');
        });
    }
});

// Roller-siden: deaktivér disciplin-checkboxes i rækken live, når "Alle discipliner" krydses af.
document.addEventListener('change', function (e) {
    if (!e.target.classList.contains('js-alle-toggle')) return;
    var row = e.target.closest('tr');
    if (!row) return;
    row.querySelectorAll('input[name="disciplines[]"]').forEach(function (cb) {
        cb.disabled = e.target.checked;
    });
});
