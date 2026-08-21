<?php
/**
 * Genberegner shows.top_rank/top_slug/top_code/has_lower/disciplin ud fra
 * classes (se Importer::recomputeShowLevels()). Køres normalt automatisk
 * som sidste trin i en CSV-import, men kan køres separat efter en manuel
 * SQL-oprydning i classes (fx sql/find_and_delete_score_summary_classes.sql),
 * saa stævnernes cachede niveau/disciplin stemmer overens igen.
 *
 * Brug:
 *   php cli/recompute_show_levels.php
 */
require __DIR__ . '/../inc/bootstrap.php';

(new Importer(db()))->recomputeShowLevels();
echo "Stævnernes niveau/disciplin er genberegnet ud fra classes.\n";
