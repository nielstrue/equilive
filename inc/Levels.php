<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Niveau-hjælper. Oversætter CSV'ens niveau-slug (club, local, ...)
 * til rang/kode og beregner stævnets samlede niveau.
 */
class Levels
{
    /** slug => [rank, code, label]  (1 = lavest niveau, 6 = højest) */
    public const MAP = [
        'club'          => [1, 'E',   'Rideskolestævne (E)'],
        'local'         => [2, 'D',   'Klubstævne (D)'],
        'regional'      => [3, 'C',   'Distriktsstævne (C)'],
        'national'      => [4, 'B',   'Landsstævne (B)'],
        'elite'         => [5, 'A',   'Elitestævne (A)'],
        'international'  => [6, 'FEI', 'Internationalt stævne (FEI)'],
    ];

    public static function rank(?string $slug): int
    {
        $slug = strtolower(trim((string)$slug));
        return self::MAP[$slug][0] ?? 0;
    }

    public static function code(?string $slug): string
    {
        $slug = strtolower(trim((string)$slug));
        return self::MAP[$slug][1] ?? '?';
    }

    public static function label(?string $slug): string
    {
        $slug = strtolower(trim((string)$slug));
        return self::MAP[$slug][2] ?? ('Ukendt (' . $slug . ')');
    }

    /** Kode ud fra rang (fx 3 => 'C'). */
    public static function codeForRank(int $rank): string
    {
        foreach (self::MAP as $m) {
            if ($m[0] === $rank) return $m[1];
        }
        return '?';
    }

    /** Slug ud fra rang. */
    public static function slugForRank(int $rank): ?string
    {
        foreach (self::MAP as $slug => $m) {
            if ($m[0] === $rank) return $slug;
        }
        return null;
    }
}
