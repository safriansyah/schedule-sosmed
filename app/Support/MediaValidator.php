<?php

namespace App\Support;

class MediaValidator
{
    /**
     * Instagram feed accepts aspect ratios between 4:5 (portrait) and
     * 1.91:1 (landscape). A small tolerance avoids rejecting exact edges.
     */
    public const MIN_RATIO = 0.8;   // 4:5
    public const MAX_RATIO = 1.91;  // 1.91:1
    public const TOLERANCE = 0.01;

    /**
     * Return an error message if the image's aspect ratio is outside the range
     * Instagram supports, or null when it's fine (or the file isn't an image).
     */
    public static function imageRatioError(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $info = @getimagesize($path);

        if ($info === false) {
            return null; // Not a readable image (e.g. a video) — skip.
        }

        [$width, $height] = $info;

        if (empty($width) || empty($height)) {
            return null;
        }

        $ratio = $width / $height;

        if ($ratio < self::MIN_RATIO - self::TOLERANCE || $ratio > self::MAX_RATIO + self::TOLERANCE) {
            return "Rasio gambar {$width}×{$height} (" . self::label($ratio) . ") tidak didukung Instagram. "
                . 'Gunakan rasio 4:5 (potret) sampai 1.91:1 (lanskap) — contoh: '
                . '1:1 (1080×1080), 4:5 (1080×1350), atau 1.91:1 (1200×628).';
        }

        return null;
    }

    /** Human-friendly ratio label, e.g. "1.5:1". */
    protected static function label(float $ratio): string
    {
        return round($ratio, 2) . ':1';
    }
}
