<?php

namespace App\Domain\Websites\Support;

use App\Domain\Tenancy\Models\Tenant;

/**
 * Code-defined salon themes. A theme is a CSS class on <html> (styles appended to app.css) plus default
 * tokens; the tenant's branding tokens (colors, fonts, radius) always win, so changes are live instantly.
 */
class ThemeRegistry
{
    public const THEMES = [
        'maison' => [
            'name' => ['en' => 'Maison', 'ar' => 'ميزون'], 'dark' => false,
            'primary' => '#C8A96A', 'accent' => '#E9D6CF', 'ink' => '#1C1A17', 'background' => '#FAF7F2',
            'font_display' => 'Playfair Display', 'font_body' => 'Inter',
            'description' => ['en' => 'Ivory, champagne gold and editorial serif — timeless couture.', 'ar' => 'عاجي وذهبي شمبانيا وخطوط تحريرية — أناقة خالدة.'],
        ],
        'atelier' => [
            'name' => ['en' => 'Atelier', 'ar' => 'أتيليه'], 'dark' => false,
            'primary' => '#9C7B5B', 'accent' => '#DCCFC0', 'ink' => '#2A2420', 'background' => '#F4EFE8',
            'font_display' => 'Cormorant Garamond', 'font_body' => 'Inter',
            'description' => ['en' => 'Warm sand, linen textures and a craft-studio feel.', 'ar' => 'رملي دافئ وملمس الكتان وروح الاستوديو الحرفي.'],
        ],
        'noir' => [
            'name' => ['en' => 'Noir', 'ar' => 'نوار'], 'dark' => true,
            'primary' => '#C8A96A', 'accent' => '#3A2A35', 'ink' => '#F5EFE6', 'background' => '#141014',
            'font_display' => 'Playfair Display', 'font_body' => 'Inter',
            'description' => ['en' => 'Midnight plum with gold light — dramatic evening glamour.', 'ar' => 'برقوقي ليلي بلمسات ذهبية — فخامة المساء.'],
        ],
        'rose' => [
            'name' => ['en' => 'Rose', 'ar' => 'روز'], 'dark' => false,
            'primary' => '#B76E79', 'accent' => '#F3DCDC', 'ink' => '#2B1D20', 'background' => '#FFF8F6',
            'font_display' => 'Playfair Display', 'font_body' => 'Inter',
            'description' => ['en' => 'Blush and rosewood — soft, romantic and feminine.', 'ar' => 'وردي ناعم وخشب الورد — رومانسي وأنثوي.'],
        ],
        'minimal' => [
            'name' => ['en' => 'Minimal', 'ar' => 'مينيمال'], 'dark' => false,
            'primary' => '#1C1A17', 'accent' => '#ECECEC', 'ink' => '#111111', 'background' => '#FFFFFF',
            'font_display' => 'Inter', 'font_body' => 'Inter',
            'description' => ['en' => 'Pure white space and crisp sans-serif — gallery calm.', 'ar' => 'مساحات بيضاء نقية وخطوط حديثة — هدوء المعارض.'],
        ],
    ];

    public static function keys(): array
    {
        return array_keys(self::THEMES);
    }

    public static function options(): array
    {
        $locale = app()->getLocale();

        return collect(self::THEMES)->mapWithKeys(fn ($t, $k) => [$k => $t['name'][$locale] ?? $t['name']['en']])->all();
    }

    /** Resolved theme (key + default tokens) for a tenant. */
    public static function for(?Tenant $tenant): array
    {
        $key = (string) ($tenant?->brand('theme') ?? 'maison');
        $key = array_key_exists($key, self::THEMES) ? $key : 'maison';

        return ['key' => $key] + self::THEMES[$key];
    }
}
