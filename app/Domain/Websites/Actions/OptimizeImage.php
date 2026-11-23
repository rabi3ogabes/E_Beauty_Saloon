<?php

namespace App\Domain\Websites\Actions;

use Illuminate\Support\Facades\Storage;

/**
 * Converts an uploaded image (public disk) to WebP with responsive widths using GD:
 *   salon/hero.jpg → salon/hero.webp + salon/hero-480.webp, -960.webp, -1600.webp
 * Returns the path of the full-size WebP (or the original path if conversion is not possible).
 */
class OptimizeImage
{
    public const WIDTHS = [480, 960, 1600];

    public function handle(?string $path, string $disk = 'public', int $quality = 82): ?string
    {
        if (! $path || ! function_exists('imagewebp')) {
            return $path;
        }
        $storage = Storage::disk($disk);
        if (! $storage->exists($path)) {
            return $path;
        }
        $src = @imagecreatefromstring($storage->get($path));
        if (! $src) {
            return $path;
        }
        imagepalettetotruecolor($src);
        imagealphablending($src, true);
        imagesavealpha($src, true);

        $base = preg_replace('/\.[a-z0-9]+$/i', '', $path);
        $width = imagesx($src);
        $height = imagesy($src);

        $full = $base.'.webp';
        $this->write($storage, $full, $src, $quality);

        foreach (self::WIDTHS as $w) {
            if ($w >= $width) {
                continue;
            }
            $h = (int) round($height * $w / $width);
            $dst = imagecreatetruecolor($w, $h);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $width, $height);
            $this->write($storage, "{$base}-{$w}.webp", $dst, $quality);
        }

        if ($full !== $path) {
            $storage->delete($path);
        }

        return $full;
    }

    protected function write($storage, string $path, $image, int $quality): void
    {
        ob_start();
        imagewebp($image, null, $quality);
        $storage->put($path, (string) ob_get_clean());
    }
}
