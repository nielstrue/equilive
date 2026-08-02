<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

render_header('Opretholdelse af status', 'status_krav');
?>
<h1>Opretholdelse af status</h1>
<p class="muted">Følger om officials opfylder DRF's aktivitetskrav for at opretholde deres niveau.</p>

<div class="grid2">
    <section>
        <h2>Springdommer</h2>
        <p class="muted">Aktivitetskrav for springdommere (niveau D/C/B/A), ud fra dømte stævner.</p>
        <a class="btn" href="<?= h(url('dommerkrav.php')) ?>">Se springdommerkrav →</a>
    </section>
    <section>
        <h2>Banedesigner</h2>
        <p class="muted">Aktivitetskrav for banedesignere (niveau D/C/B/A), ud fra ansvarlige og
            assisterede banedesigner-opgaver.</p>
        <a class="btn" href="<?= h(url('banedesignerkrav.php')) ?>">Se banedesignerkrav →</a>
    </section>
</div>
<?php
render_footer();
