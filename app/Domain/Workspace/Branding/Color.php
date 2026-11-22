<?php

namespace App\Domain\Workspace\Branding;

/** Tiny colour helper for the brand kit generator (hex <-> rgb/hsl, mixing, contrast). */
final class Color
{
    public static function toRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public static function toHex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', ...array_map(fn ($c) => max(0, min(255, (int) round($c))), $rgb));
    }

    /** Mix two colours; $weight = share of $b (0..1). */
    public static function mix(string $a, string $b, float $weight): string
    {
        [$r1, $g1, $b1] = self::toRgb($a);
        [$r2, $g2, $b2] = self::toRgb($b);

        return self::toHex([$r1 + ($r2 - $r1) * $weight, $g1 + ($g2 - $g1) * $weight, $b1 + ($b2 - $b1) * $weight]);
    }

    public static function lighten(string $hex, float $amount): string
    {
        return self::mix($hex, '#FFFFFF', $amount);
    }

    public static function darken(string $hex, float $amount): string
    {
        return self::mix($hex, '#000000', $amount);
    }

    /** [h 0..360, s 0..1, l 0..1] */
    public static function toHsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn ($c) => $c / 255, self::toRgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) {
            return [0.0, 0.0, $l];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h * 60, $s, $l];
    }

    public static function saturation(string $hex): float
    {
        return self::toHsl($hex)[1];
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function ($c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::toRgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** Readable text colour (ink or ivory) on a background. */
    public static function onColor(string $hex): string
    {
        return self::luminance($hex) > 0.45 ? '#1C1A17' : '#FAF7F2';
    }

    public static function distance(string $a, string $b): float
    {
        [$r1, $g1, $b1] = self::toRgb($a);
        [$r2, $g2, $b2] = self::toRgb($b);

        return sqrt(($r1 - $r2) ** 2 + ($g1 - $g2) ** 2 + ($b1 - $b2) ** 2);
    }

    public static function isHex(?string $value): bool
    {
        return is_string($value) && (bool) preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value);
    }
}
