<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Models\ClientLook;
use App\Domain\AIStudio\Support\PhotoVault;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Exceptions\DomainException;

/**
 * Share card for WhatsApp / Instagram: before | after side by side with a salon watermark band
 * (pure GD — no Intervention). Returns JPEG bytes.
 */
class ComposeShareImage
{
    public function __construct(protected PhotoVault $vault) {}

    public function handle(ClientLook $look, Tenant $tenant): string
    {
        $after = $this->vault->get($look->after_path);
        if (! $after || ! ($afterImg = @imagecreatefromstring($after))) {
            throw new DomainException(__('ai_studio.errors.look_not_ready'));
        }
        $before = $this->vault->get($look->before_path);
        $beforeImg = $before ? @imagecreatefromstring($before) : null;

        $h = 1080;
        $panelW = 810; // 3:4 panels → 1620x1080 + band
        $band = 120;
        $canvas = imagecreatetruecolor($beforeImg ? $panelW * 2 : $panelW, $h + $band);
        $ivory = $this->color($canvas, '#FAF7F2');
        imagefill($canvas, 0, 0, $ivory);

        $x = 0;
        foreach (array_filter([$beforeImg, $afterImg]) as $img) {
            $this->cover($canvas, $img, $x, 0, $panelW, $h);
            $x += $panelW;
        }

        $brand = (string) $tenant->brand('primary', '#C8A96A');
        imagefilledrectangle($canvas, 0, $h, imagesx($canvas), $h + $band, $this->color($canvas, '#1C1A17'));
        imagefilledrectangle($canvas, 0, $h, imagesx($canvas), $h + 4, $this->color($canvas, $brand));
        $this->text($canvas, mb_strtoupper($tenant->name), 40, $h + 34, 4, $this->color($canvas, '#FAF7F2'));
        $this->text($canvas, parse_url($tenant->url(), PHP_URL_HOST).'  ·  '.$look->reference, 40, $h + 78, 2, $this->color($canvas, $brand));

        ob_start();
        imagejpeg($canvas, null, 86);
        $jpeg = (string) ob_get_clean();

        $look->forceFill(['shared_at' => now()])->save();

        return $jpeg;
    }

    /** object-fit: cover */
    protected function cover($dst, $src, int $x, int $y, int $w, int $h): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($w / $sw, $h / $sh);
        $cw = (int) round($w / $scale);
        $ch = (int) round($h / $scale);
        imagecopyresampled($dst, $src, $x, $y, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $w, $h, $cw, $ch);
    }

    /** Built-in GD font, scaled up for legibility (no TTF dependency). */
    protected function text($canvas, string $text, int $x, int $y, int $scale, int $color): void
    {
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?: '';
        $font = 5;
        $w = max(1, imagefontwidth($font) * strlen($text));
        $hh = imagefontheight($font);
        $tmp = imagecreatetruecolor($w, $hh);
        imagesavealpha($tmp, true);
        $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefill($tmp, 0, 0, $transparent);
        imagestring($tmp, $font, 0, 0, $text, imagecolorallocate($tmp, ($color >> 16) & 255, ($color >> 8) & 255, $color & 255));
        imagecopyresampled($canvas, $tmp, $x, $y, 0, 0, (int) ($w * $scale / 2), (int) ($hh * $scale / 2), $w, $hh);
    }

    protected function color($img, string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        [$r, $g, $b] = sscanf(str_pad(substr($hex, 0, 6), 6, '0'), '%02x%02x%02x');

        return imagecolorallocate($img, (int) $r, (int) $g, (int) $b);
    }
}
