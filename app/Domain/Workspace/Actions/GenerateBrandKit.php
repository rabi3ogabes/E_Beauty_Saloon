<?php

namespace App\Domain\Workspace\Actions;

use App\Domain\Workspace\Branding\Color;

/**
 * Brand kit auto-generator: logo -> dominant palette -> three "quiet luxury" theme presets
 * (primary / accent / ink / background + font pair + button style) the owner applies in one click.
 *
 * @return array{palette: list<string>, presets: list<array<string, string>>}
 */
class GenerateBrandKit
{
    public const FONT_PAIRS = [
        'Playfair Display' => 'Inter',
        'Cormorant Garamond' => 'Manrope',
        'Bodoni Moda' => 'Inter',
        'DM Serif Display' => 'DM Sans',
        'Marcellus' => 'Lato',
    ];

    public function __construct(protected ExtractBrandPalette $extractor) {}

    public function handle(string $logoPath): array
    {
        return ['palette' => $palette = $this->extractor->handle($logoPath), 'presets' => static::presetsFor($palette)];
    }

    /** @param  list<string>  $palette */
    public static function presetsFor(array $palette): array
    {
        // Brand colour = the most saturated of the dominant colours (logos are often ink + one hue).
        $brand = collect($palette)->sortByDesc(fn ($c) => Color::saturation($c) * 2 + (1 - abs(Color::toHsl($c)[2] - 0.5)))->first() ?? '#C8A96A';
        $second = collect($palette)->first(fn ($c) => Color::distance($c, $brand) > 60) ?? '#C8A96A';
        [, $s, $l] = Color::toHsl($brand);
        // Too neutral a logo (black/white/grey): borrow champagne gold for the accent.
        $neutral = $s < 0.12;
        $brandTone = $neutral ? '#C8A96A' : $brand;

        $deep = Color::darken($brandTone, $l > 0.5 ? 0.45 : 0.25);

        return [
            [
                'key' => 'maison',
                'name' => __('workspace.branding.preset_maison'),
                'primary' => $brandTone,
                'accent' => Color::mix($brandTone, '#FAF7F2', 0.78),
                'ink' => '#1C1A17',
                'background' => '#FAF7F2',
                'font_display' => 'Playfair Display',
                'font_body' => 'Inter',
                'button_style' => 'pill',
            ],
            [
                'key' => 'atelier',
                'name' => __('workspace.branding.preset_atelier'),
                'primary' => $deep,
                'accent' => $neutral ? '#E9D6CF' : Color::mix($second, '#FFFFFF', 0.55),
                'ink' => Color::mix($deep, '#000000', 0.55),
                'background' => Color::mix($brandTone, '#FFFFFF', 0.94),
                'font_display' => 'Cormorant Garamond',
                'font_body' => 'Manrope',
                'button_style' => 'rounded',
            ],
            [
                'key' => 'noir',
                'name' => __('workspace.branding.preset_noir'),
                'primary' => Color::mix($brandTone, '#1C1A17', 0.82),
                'accent' => $brandTone,
                'ink' => '#1C1A17',
                'background' => Color::mix('#FAF7F2', $brandTone, 0.04),
                'font_display' => 'Bodoni Moda',
                'font_body' => 'Inter',
                'button_style' => 'pill',
            ],
        ];
    }
}
