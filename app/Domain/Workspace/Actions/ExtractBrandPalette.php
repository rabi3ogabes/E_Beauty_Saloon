<?php

namespace App\Domain\Workspace\Actions;

use App\Domain\Workspace\Branding\Color;
use App\Support\Exceptions\DomainException;

/**
 * Extracts the dominant colours of a logo with GD: downsample, bucket (4 bits/channel),
 * ignore transparent and near-white background pixels, merge similar buckets.
 *
 * @return list<string> hex colours, most dominant first
 */
class ExtractBrandPalette
{
    public function handle(string $path, int $count = 5): array
    {
        $raw = is_file($path) ? @file_get_contents($path) : null;
        $image = $raw ? @imagecreatefromstring($raw) : false;
        if (! $image) {
            throw new DomainException(__('workspace.branding.logo_unreadable'));
        }

        $size = 64;
        $thumb = imagecreatetruecolor($size, $size);
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $size, $size, imagesx($image), imagesy($image));

        $buckets = [];
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                $c = imagecolorat($thumb, $x, $y);
                $alpha = ($c >> 24) & 0x7F;
                if ($alpha > 90) {
                    continue; // transparent
                }
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                if ($r > 242 && $g > 242 && $b > 242) {
                    continue; // paper-white background
                }
                $key = (($r >> 4) << 8) | (($g >> 4) << 4) | ($b >> 4);
                $buckets[$key] ??= [0, 0, 0, 0];
                $buckets[$key][0] += $r;
                $buckets[$key][1] += $g;
                $buckets[$key][2] += $b;
                $buckets[$key][3]++;
            }
        }
        imagedestroy($thumb);
        imagedestroy($image);

        uasort($buckets, fn ($a, $b) => $b[3] <=> $a[3]);

        $palette = [];
        foreach ($buckets as [$r, $g, $b, $n]) {
            $hex = Color::toHex([$r / $n, $g / $n, $b / $n]);
            if (collect($palette)->contains(fn ($p) => Color::distance($p, $hex) < 48)) {
                continue;
            }
            $palette[] = $hex;
            if (count($palette) >= $count) {
                break;
            }
        }

        return $palette ?: ['#1C1A17'];
    }
}
