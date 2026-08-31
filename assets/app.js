// Mobil-burgermenu i topbar'en (se render_header() i inc/layout.php).
(function () {
    var toggle = document.getElementById('nav-toggle');
    var collapse = document.getElementById('topbar-collapse');
    if (!toggle || !collapse) return;

    toggle.addEventListener('click', function () {
        var open = collapse.classList.toggle('open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    // Luk menuen når man klikker et link i den, saa den ikke staar aaben efter navigation.
    collapse.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () {
            collapse.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    });
})();

// Brugere.php: "Kopiér"-knap ved siden af et nyt 1. gangs-kodeord (se kodeord_visning()).
function kopierKodeord(id, knap) {
    var el = document.getElementById(id);
    if (!el) return;
    var tekst = el.textContent;
    var visFeedback = function () {
        var oprindelig = knap.textContent;
        knap.textContent = 'Kopieret!';
        setTimeout(function () { knap.textContent = oprindelig; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(tekst).then(visFeedback, function () {
            kopierKodeordFallback(tekst);
            visFeedback();
        });
    } else {
        kopierKodeordFallback(tekst);
        visFeedback();
    }
}
function kopierKodeordFallback(tekst) {
    var ta = document.createElement('textarea');
    ta.value = tekst;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) { /* ignoreres - clipboard-api er allerede forsøgt */ }
    document.body.removeChild(ta);
}

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

// Brugere.php: foreslå et 1. gangs-kodeord der er nemt at huske (to ridesports-ord + tal).
// Opfylder altid kodeordskravene (stort bogstav fra hvert ord, cifre til sidst).
var ORD_FORSLAG = ['Hest', 'Sadel', 'Trense', 'Stald', 'Ridebane', 'Spring', 'Galop', 'Hoppe',
    'Ponny', 'Manege', 'Dressur', 'Travet', 'Bidsel', 'Stigboejle', 'Ridehjelm', 'Hovslag'];
function foreslaaKodeord() {
    var felt = document.getElementById('n_kodeord');
    if (!felt) return;
    var w1 = ORD_FORSLAG[Math.floor(Math.random() * ORD_FORSLAG.length)];
    var w2;
    do { w2 = ORD_FORSLAG[Math.floor(Math.random() * ORD_FORSLAG.length)]; } while (w2 === w1);
    var tal = 10 + Math.floor(Math.random() * 90);
    felt.value = w1 + w2 + tal;
}

// Skift_kodeord.php: styrke-indikator og kravliste for det nye kodeord.
(function () {
    var nytFelt = document.getElementById('nyt');
    if (!nytFelt) return;
    var q = function (s) { return document.querySelector(s); };

    function kodeordStyrke(pw) {
        var score = 0;
        if (pw.length >= 8) score++;
        if (pw.length >= 12) score++;
        var klasser = [/[a-zæøå]/, /[A-ZÆØÅ]/, /[0-9]/, /[^a-zA-ZæøåÆØÅ0-9]/].filter(function (r) { return r.test(pw); }).length;
        if (klasser >= 3) score++;
        if (klasser >= 4) score++;
        return Math.min(score, 4);
    }
    var NIVEAUER = ['Meget svagt', 'Svagt', 'Rimeligt', 'Godt', 'Stærkt'];
    var FARVER = ['#c0392b', '#c0392b', '#e0a500', '#2f8f2f', '#1f6f54'];

    function saet(el, ok) { el.classList.toggle('ok', ok); }

    function opdaterMatch() {
        var pw = nytFelt.value;
        var gentaget = q('#gentaget').value;
        var el = q('#match_tekst');
        if (!gentaget) { el.innerHTML = '&nbsp;'; return; }
        if (pw === gentaget) {
            el.textContent = '✓ Kodeordene er ens'; el.style.color = '#2f8f2f';
        } else {
            el.textContent = 'Kodeordene er ikke ens endnu'; el.style.color = 'var(--muted)';
        }
    }

    function opdaterStyrke() {
        var pw = nytFelt.value;
        var fill = q('#styrke_fill');
        var tekst = q('#styrke_tekst');
        if (!pw) {
            fill.style.width = '0'; tekst.innerHTML = '&nbsp;';
        } else {
            var s = kodeordStyrke(pw);
            fill.style.width = ((s + 1) * 20) + '%';
            fill.style.background = FARVER[s];
            tekst.textContent = NIVEAUER[s];
            tekst.style.color = FARVER[s];
        }
        saet(q('#krav_laengde'), pw.length >= 8);
        saet(q('#krav_stort'), /[A-ZÆØÅ]/.test(pw));
        saet(q('#krav_tal'), /[0-9]/.test(pw));
        opdaterMatch();
    }

    nytFelt.oninput = opdaterStyrke;
    q('#gentaget').oninput = opdaterMatch;
    opdaterStyrke();
})();
