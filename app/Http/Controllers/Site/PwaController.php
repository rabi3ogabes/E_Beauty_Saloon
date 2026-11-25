<?php

namespace App\Http\Controllers\Site;

use App\Domain\Websites\Models\PushSubscription;
use App\Domain\Websites\Support\SiteSeo;
use App\Domain\Websites\Support\ThemeRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Salon PWA: per-tenant manifest, icons from the logo, service worker and web push subscriptions. */
class PwaController extends Controller
{
    public function manifest()
    {
        $t = tenant();
        $theme = ThemeRegistry::for($t);
        $color = SiteSeo::cssColor($t->brand('primary'), $theme['primary']);
        $bg = $theme['dark'] ? $theme['background'] : SiteSeo::cssColor($t->brand('background'), $theme['background']);

        return response()->json([
            'id' => '/',
            'name' => $t->name,
            'short_name' => mb_substr($t->name, 0, 12),
            'description' => Str::limit(strip_tags((string) $t->brand('about')), 120),
            'lang' => app()->getLocale(),
            'dir' => in_array(app()->getLocale(), config('salon.rtl_locales'), true) ? 'rtl' : 'ltr',
            'start_url' => '/?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => $bg,
            'theme_color' => $color,
            'icons' => [
                ['src' => url('/pwa/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => url('/pwa/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => url('/pwa/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => array_values(array_filter([
                $t->hasModule('booking') ? ['name' => __('site.cta.book_now'), 'url' => '/book'] : null,
                $t->hasModule('ai_studio') ? ['name' => __('site.nav.studio'), 'url' => '/studio'] : null,
                ['name' => __('site.nav.account'), 'url' => '/account'],
            ])),
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** App icon from the tenant logo (centered on the brand background) or a brand monogram. */
    public function icon(int $size)
    {
        abort_unless(in_array($size, [192, 512], true), 404);
        $t = tenant();
        $key = 'pwa-icon:'.$t->id.':'.$size.':'.md5(json_encode([$t->brand('logo_path'), $t->brand('primary'), $t->brand('background'), $t->brand('theme')]));

        // Cached base64-encoded: binary PNG bytes are rejected by text-based cache stores (e.g. MySQL utf8mb4).
        $png = base64_decode((string) cache()->remember($key, 86400, function () use ($t, $size) {
            $theme = ThemeRegistry::for($t);
            $img = imagecreatetruecolor($size, $size);
            $bg = $this->rgb($img, $theme['dark'] ? $theme['background'] : SiteSeo::cssColor($t->brand('background'), $theme['background']));
            imagefill($img, 0, 0, $bg);
            $logo = $t->brand('logo_path');
            $src = $logo && Storage::disk('public')->exists($logo) ? @imagecreatefromstring(Storage::disk('public')->get($logo)) : null;
            if ($src) {
                $pad = (int) ($size * 0.18);
                $box = $size - 2 * $pad;
                $scale = min($box / imagesx($src), $box / imagesy($src));
                $w = (int) (imagesx($src) * $scale);
                $h = (int) (imagesy($src) * $scale);
                imagecopyresampled($img, $src, (int) (($size - $w) / 2), (int) (($size - $h) / 2), 0, 0, $w, $h, imagesx($src), imagesy($src));
            } else {
                // Monogram: brand-coloured disc with a fine ring.
                $brand = $this->rgb($img, SiteSeo::cssColor($t->brand('primary'), $theme['primary']));
                imagefilledellipse($img, (int) ($size / 2), (int) ($size / 2), (int) ($size * 0.62), (int) ($size * 0.62), $brand);
                imagesetthickness($img, max(2, (int) ($size / 96)));
                imageellipse($img, (int) ($size / 2), (int) ($size / 2), (int) ($size * 0.74), (int) ($size * 0.74), $brand);
            }
            ob_start();
            imagepng($img);

            return base64_encode((string) ob_get_clean());
        }));

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function serviceWorker()
    {
        $version = substr(md5(tenant()->id.'|'.optional(tenant()->updated_at)->timestamp.'|'.config('app.key')), 0, 10);

        return response()->view('site.website.sw', ['version' => $version], 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000', 'starts_with:https://'],
            'keys.p256dh' => ['nullable', 'string', 'max:255'],
            'keys.auth' => ['nullable', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'max:20'],
        ]);

        PushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'tenant_id' => tenant()->id,
            'client_id' => $request->user('client')?->id,
            'endpoint' => $data['endpoint'],
            'public_key' => data_get($data, 'keys.p256dh'),
            'auth_token' => data_get($data, 'keys.auth'),
            'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 250),
            'locale' => app()->getLocale(),
        ]);

        return response()->json(['ok' => true, 'vapid' => (bool) config('ai.vapid.public_key')], 201);
    }

    public function unsubscribe(Request $request)
    {
        $request->validate(['endpoint' => ['required', 'string']]);
        PushSubscription::where('tenant_id', tenant()->id)->where('endpoint_hash', hash('sha256', $request->input('endpoint')))->delete();

        return response()->json(['ok' => true]);
    }

    protected function rgb($img, string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return imagecolorallocate($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }
}
