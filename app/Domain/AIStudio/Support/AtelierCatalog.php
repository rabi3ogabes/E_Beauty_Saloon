<?php

namespace App\Domain\AIStudio\Support;

use App\Domain\AIStudio\Models\AtelierDesign;
use App\Domain\AIStudio\Models\ClientLook;
use App\Domain\Catalog\Models\Service;

/**
 * Live Atelier: the design options for lashes, brows, lips and nails, in one place.
 *
 * The same definition feeds the on-device preview (renderer settings, colours), the
 * labels (en/ar), the AI prompt and the booking summary. Client input is never used as
 * text: normalize() keeps only known keys and values, so the AI prompt is always built
 * from this catalog.
 */
class AtelierCatalog
{
    /** category => [task, framing, face feature?, service keywords] */
    public const CATEGORIES = [
        'lashes' => ['task' => 'eyes_tryon', 'framing' => 'face', 'face' => true, 'keywords' => ['lash', 'رموش']],
        'brows' => ['task' => 'eyes_tryon', 'framing' => 'face', 'face' => true, 'keywords' => ['brow', 'حواجب', 'حاجب']],
        'lips' => ['task' => 'lips_tryon', 'framing' => 'face', 'face' => true, 'keywords' => ['lip', 'شفا', 'شفاه']],
        'nails' => ['task' => 'nails_tryon', 'framing' => 'hand', 'face' => false, 'keywords' => ['nail', 'manicure', 'gel', 'أظافر', 'مانيكير']],
    ];

    public static function definition(): array
    {
        return [
            'lashes' => [
                'styles' => [
                    // profile: relative length from the outer corner (first) to the inner corner (last); sweep: outward tilt.
                    'natural' => ['en' => 'Natural', 'ar' => 'طبيعي', 'desc_en' => 'Soft, understated definition', 'desc_ar' => 'تحديد ناعم وهادئ',
                        'profile' => [0.78, 0.86, 0.84, 0.72, 0.55], 'sweep' => 0.15, 'density' => 0.85, 'spikes' => 0,
                        'prompt' => 'natural classic eyelash extensions with soft, even length and a light, understated finish'],
                    'cat_eye' => ['en' => 'Cat Eye', 'ar' => 'عين القطة', 'desc_en' => 'An elongated, lifted finish', 'desc_ar' => 'لمسة ممدودة ومرفوعة',
                        'profile' => [1.0, 0.92, 0.76, 0.6, 0.45], 'sweep' => 0.35, 'density' => 1.0, 'spikes' => 0,
                        'prompt' => 'cat eye eyelash extensions, longest at the outer corners and gradually shorter towards the inner corners, for an elongated, lifted eye'],
                    'doll_eye' => ['en' => 'Doll Eye', 'ar' => 'عين الدمية', 'desc_en' => 'Open, bright and beautifully round', 'desc_ar' => 'عين مفتوحة ومشرقة ومستديرة',
                        'profile' => [0.66, 0.86, 1.0, 0.86, 0.62], 'sweep' => 0.05, 'density' => 1.0, 'spikes' => 0,
                        'prompt' => 'doll eye eyelash extensions, longest at the centre of the eye over the pupil and shorter at both corners, for an open, round, bright eye'],
                    'foxy' => ['en' => 'Foxy', 'ar' => 'عين الثعلب', 'desc_en' => 'A sleek, softly swept silhouette', 'desc_ar' => 'خط أنيق ممسوح للخارج',
                        'profile' => [1.0, 0.95, 0.62, 0.48, 0.4], 'sweep' => 0.6, 'density' => 0.95, 'spikes' => 0,
                        'prompt' => 'foxy eye eyelash extensions, dramatically longer and swept outward at the outer corners with short inner lashes, for a sleek, lifted fox-eye silhouette'],
                    'wispy' => ['en' => 'Wispy', 'ar' => 'ويسبي', 'desc_en' => 'Feathery texture, delicate spikes', 'desc_ar' => 'ملمس ريشي ونتوءات رقيقة',
                        'profile' => [0.92, 0.84, 0.8, 0.68, 0.52], 'sweep' => 0.25, 'density' => 1.0, 'spikes' => 1,
                        'prompt' => 'wispy eyelash extensions with a feathery, textured finish and longer spikes spaced along the lash line'],
                    'soft_volume' => ['en' => 'Soft Volume', 'ar' => 'فوليوم ناعم', 'desc_en' => 'A fuller, velvet-like lash line', 'desc_ar' => 'خط رموش أكثف كالمخمل',
                        'profile' => [0.9, 0.9, 0.86, 0.76, 0.6], 'sweep' => 0.2, 'density' => 1.6, 'spikes' => 0,
                        'prompt' => 'soft volume eyelash extensions with a dense, fluffy, velvety lash line'],
                ],
                'curls' => [
                    'B' => ['en' => 'Soft B', 'ar' => 'ناعم B‏', 'bend' => 0.16, 'prompt' => 'a soft B curl'],
                    'C' => ['en' => 'Classic C', 'ar' => 'كلاسيك C‏', 'bend' => 0.28, 'prompt' => 'a classic C curl'],
                    'D' => ['en' => 'Dramatic D', 'ar' => 'درامي D‏', 'bend' => 0.4, 'prompt' => 'a dramatic D curl'],
                    'L' => ['en' => 'Lifted L', 'ar' => 'مرفوع L‏', 'bend' => 0.5, 'prompt' => 'an L curl that lifts sharply upwards'],
                ],
                'length' => ['min' => 8, 'max' => 16, 'default' => 12, 'unit' => 'mm'],
                'fullness' => ['min' => 20, 'max' => 100, 'default' => 65],
                'defaults' => ['style' => 'cat_eye', 'length' => 12, 'curl' => 'C', 'fullness' => 65],
            ],
            'brows' => [
                'shapes' => [
                    'natural' => ['en' => 'Natural', 'ar' => 'طبيعي', 'desc_en' => 'Your shape, softly defined', 'desc_ar' => 'شكلك الطبيعي بتحديد ناعم', 'arch' => 0.0, 'thick' => 1.0, 'tail' => 1.0, 'lift' => 0.0, 'strokes' => 'along', 'prompt' => 'softly defined natural eyebrows that follow the person\'s own brow shape'],
                    'soft_arch' => ['en' => 'Soft Arch', 'ar' => 'قوس ناعم', 'desc_en' => 'A gentle, feminine lift', 'desc_ar' => 'رفعة أنثوية هادئة', 'arch' => 0.35, 'thick' => 1.0, 'tail' => 1.05, 'lift' => 0.0, 'strokes' => 'along', 'prompt' => 'eyebrows with a soft, gentle arch'],
                    'high_arch' => ['en' => 'High Arch', 'ar' => 'قوس عالٍ', 'desc_en' => 'Sculpted and lifted', 'desc_ar' => 'منحوت ومرفوع', 'arch' => 0.75, 'thick' => 0.95, 'tail' => 1.08, 'lift' => 0.08, 'strokes' => 'along', 'prompt' => 'sculpted eyebrows with a high, defined arch'],
                    'straight' => ['en' => 'Straight', 'ar' => 'مستقيم', 'desc_en' => 'Youthful, modern and flat', 'desc_ar' => 'عصري وشبابي ومستقيم', 'arch' => -0.25, 'thick' => 1.05, 'tail' => 1.0, 'lift' => 0.0, 'strokes' => 'along', 'prompt' => 'straight, low-arched modern eyebrows'],
                    'laminated' => ['en' => 'Laminated', 'ar' => 'لامينيشن', 'desc_en' => 'Brushed-up, fluffy hairs', 'desc_ar' => 'شعيرات ممشطة للأعلى ومنفوشة', 'arch' => 0.2, 'thick' => 1.25, 'tail' => 1.0, 'lift' => 0.05, 'strokes' => 'up', 'prompt' => 'laminated eyebrows with fluffy, brushed-up hairs'],
                    'bold' => ['en' => 'Bold', 'ar' => 'جريء', 'desc_en' => 'Full, defined and striking', 'desc_ar' => 'كثيف ومحدد ولافت', 'arch' => 0.3, 'thick' => 1.4, 'tail' => 1.1, 'lift' => 0.0, 'strokes' => 'along', 'prompt' => 'bold, full and clearly defined eyebrows'],
                ],
                'shades' => [
                    'honey_blonde' => ['en' => 'Honey blonde', 'ar' => 'أشقر عسلي', 'hex' => '#9C7A54'],
                    'taupe' => ['en' => 'Taupe', 'ar' => 'توب', 'hex' => '#7A6656'],
                    'soft_brown' => ['en' => 'Soft brown', 'ar' => 'بني ناعم', 'hex' => '#5E4535'],
                    'dark_brown' => ['en' => 'Dark brown', 'ar' => 'بني غامق', 'hex' => '#3B2A20'],
                    'soft_black' => ['en' => 'Soft black', 'ar' => 'أسود ناعم', 'hex' => '#221A17'],
                ],
                'intensity' => ['min' => 20, 'max' => 100, 'default' => 60],
                'defaults' => ['shape' => 'soft_arch', 'shade' => 'dark_brown', 'intensity' => 60],
            ],
            'lips' => [
                'shades' => [
                    'nude_rose' => ['en' => 'Nude rose', 'ar' => 'وردي نود', 'hex' => '#C98A86'],
                    'peach' => ['en' => 'Peach', 'ar' => 'خوخي', 'hex' => '#E39A7E'],
                    'coral' => ['en' => 'Coral', 'ar' => 'مرجاني', 'hex' => '#E4685A'],
                    'mauve' => ['en' => 'Mauve', 'ar' => 'موف', 'hex' => '#A86A7A'],
                    'berry' => ['en' => 'Berry', 'ar' => 'توتي', 'hex' => '#8E2F4F'],
                    'classic_red' => ['en' => 'Classic red', 'ar' => 'أحمر كلاسيك', 'hex' => '#B3202C'],
                    'cherry' => ['en' => 'Cherry', 'ar' => 'كرزي', 'hex' => '#7A1424'],
                ],
                'finishes' => [
                    'blush' => ['en' => 'Lip blush', 'ar' => 'بلش الشفاه', 'prompt' => 'a soft, semi-permanent lip blush tint that looks like naturally flushed lips'],
                    'defined' => ['en' => 'Defined', 'ar' => 'محدد', 'prompt' => 'a defined lip blush with a crisp, softly outlined lip border'],
                    'gloss' => ['en' => 'Glossy', 'ar' => 'لامع', 'prompt' => 'a lip tint with a glossy, hydrated sheen'],
                ],
                'intensity' => ['min' => 20, 'max' => 100, 'default' => 55],
                'defaults' => ['shade' => 'nude_rose', 'finish' => 'blush', 'intensity' => 55],
            ],
            'nails' => [
                'shapes' => [
                    'round' => ['en' => 'Round', 'ar' => 'دائري'],
                    'oval' => ['en' => 'Oval', 'ar' => 'بيضاوي'],
                    'almond' => ['en' => 'Almond', 'ar' => 'لوزي'],
                    'square' => ['en' => 'Square', 'ar' => 'مربع'],
                    'coffin' => ['en' => 'Coffin', 'ar' => 'كوفن'],
                    'stiletto' => ['en' => 'Stiletto', 'ar' => 'ستيليتو'],
                ],
                'lengths' => [
                    'short' => ['en' => 'Short', 'ar' => 'قصير', 'ext' => 0.05],
                    'medium' => ['en' => 'Medium', 'ar' => 'متوسط', 'ext' => 0.3],
                    'long' => ['en' => 'Long', 'ar' => 'طويل', 'ext' => 0.6],
                ],
                'colors' => [
                    'milky_nude' => ['en' => 'Milky nude', 'ar' => 'نود حليبي', 'hex' => '#E9D3C8'],
                    'ballet_pink' => ['en' => 'Ballet pink', 'ar' => 'وردي باليه', 'hex' => '#E8B4B8'],
                    'coral' => ['en' => 'Coral', 'ar' => 'مرجاني', 'hex' => '#F0705F'],
                    'classic_red' => ['en' => 'Classic red', 'ar' => 'أحمر كلاسيك', 'hex' => '#B0131F'],
                    'burgundy' => ['en' => 'Burgundy', 'ar' => 'عنابي', 'hex' => '#5E1424'],
                    'champagne' => ['en' => 'Champagne', 'ar' => 'شمبانيا', 'hex' => '#D8BE8E'],
                    'mocha' => ['en' => 'Mocha', 'ar' => 'موكا', 'hex' => '#7B5A4B'],
                    'black' => ['en' => 'Noir', 'ar' => 'أسود', 'hex' => '#1C1A1C'],
                ],
                'finishes' => [
                    'gloss' => ['en' => 'Gloss', 'ar' => 'لامع', 'prompt' => 'a high-gloss gel finish'],
                    'matte' => ['en' => 'Matte', 'ar' => 'مطفي', 'prompt' => 'a velvety matte finish'],
                    'chrome' => ['en' => 'Chrome', 'ar' => 'كروم', 'prompt' => 'a mirror-like chrome finish'],
                    'french' => ['en' => 'French', 'ar' => 'فرنسي', 'prompt' => 'a French manicure: sheer natural base with a clean tip in the chosen colour'],
                ],
                'defaults' => ['shape' => 'almond', 'length' => 'medium', 'color' => 'milky_nude', 'finish' => 'gloss'],
            ],
        ];
    }

    public static function has(string $category): bool
    {
        return isset(self::CATEGORIES[$category]);
    }

    /** Keep only known keys and values; clamp numbers. Unknown or missing values fall back to the defaults. */
    public static function normalize(string $category, array $params): array
    {
        $d = self::definition()[$category];
        $pick = fn (string $key, array $set) => array_key_exists((string) ($params[$key] ?? ''), $set) ? (string) $params[$key] : $d['defaults'][$key];
        $num = fn (string $key, array $range) => (int) max($range['min'], min($range['max'], (int) round((float) ($params[$key] ?? $d['defaults'][$key]))));

        return match ($category) {
            'lashes' => ['style' => $pick('style', $d['styles']), 'length' => $num('length', $d['length']), 'curl' => $pick('curl', $d['curls']), 'fullness' => $num('fullness', $d['fullness'])],
            'brows' => ['shape' => $pick('shape', $d['shapes']), 'shade' => $pick('shade', $d['shades']), 'intensity' => $num('intensity', $d['intensity'])],
            'lips' => ['shade' => $pick('shade', $d['shades']), 'finish' => $pick('finish', $d['finishes']), 'intensity' => $num('intensity', $d['intensity'])],
            'nails' => ['shape' => $pick('shape', $d['shapes']), 'length' => $pick('length', $d['lengths']), 'color' => $pick('color', $d['colors']), 'finish' => $pick('finish', $d['finishes'])],
        };
    }

    /** One line for the client, the booking and the artist: "Lashes · Cat Eye · 12 mm · C curl · 65% fullness". */
    public static function summary(string $category, array $p, ?string $locale = null): string
    {
        $l = ($locale ?? app()->getLocale()) === 'ar' ? 'ar' : 'en';
        $d = self::definition()[$category];
        $parts = match ($category) {
            'lashes' => [$d['styles'][$p['style']][$l], $p['length'].($l === 'ar' ? ' مم' : ' mm'), $d['curls'][$p['curl']][$l], $p['fullness'].'%'],
            'brows' => [$d['shapes'][$p['shape']][$l], $d['shades'][$p['shade']][$l], $p['intensity'].'%'],
            'lips' => [$d['shades'][$p['shade']][$l], $d['finishes'][$p['finish']][$l], $p['intensity'].'%'],
            'nails' => [$d['colors'][$p['color']][$l], $d['shapes'][$p['shape']][$l], $d['lengths'][$p['length']][$l], $d['finishes'][$p['finish']][$l]],
        };

        return __('ai_studio.atelier.categories.'.$category, [], $l).' · '.implode(' · ', $parts);
    }

    /** The AI instruction, built from catalog text only. */
    public static function prompt(string $category, array $p): string
    {
        $d = self::definition()[$category];
        $look = match ($category) {
            'lashes' => 'Apply '.$d['styles'][$p['style']]['prompt'].' to both upper lash lines: longest lashes about '.$p['length'].' mm, '
                .$d['curls'][$p['curl']]['prompt'].', '.$p['fullness'].'% fullness. Individually applied, realistic lash extensions; no eyeliner, no other makeup.',
            'brows' => 'Reshape and tint the eyebrows into '.$d['shapes'][$p['shape']]['prompt'].', in a '.strtolower($d['shades'][$p['shade']]['en'])
                .' shade ('.$d['shades'][$p['shade']]['hex'].') at '.$p['intensity'].'% intensity, with fine, realistic individual brow hairs. Both brows symmetrical.',
            'lips' => 'Give the lips '.$d['finishes'][$p['finish']]['prompt'].' in '.strtolower($d['shades'][$p['shade']]['en']).' ('.$d['shades'][$p['shade']]['hex']
                .') at '.$p['intensity'].'% intensity, following the natural lip shape. Keep the teeth and the skin around the mouth unchanged.',
            'nails' => 'Give every visible fingernail a professional '.strtolower($d['lengths'][$p['length']]['en']).' '.strtolower($d['shapes'][$p['shape']]['en'])
                .' manicure in '.strtolower($d['colors'][$p['color']]['en']).' ('.$d['colors'][$p['color']]['hex'].') with '.$d['finishes'][$p['finish']]['prompt'].'. Neat cuticles.',
        };
        $area = self::CATEGORIES[$category]['framing'] === 'hand' ? 'the fingernails' : 'the '.($category === 'lips' ? 'lips' : ($category === 'brows' ? 'eyebrows' : 'eyelashes'));

        return $look."\n\nThe second image is the client's photo with this design drawn on as a placement guide: match its shape, length and position, "
            .'but render it photorealistically, as a real salon result. Edit only '.$area.'. Keep the person\'s identity, face, skin texture, hair, body, '
            .'clothing, lighting and background exactly unchanged. No text, no watermark.';
    }

    /** The salon service to book for a category: first active service whose name matches the category's keywords. */
    public static function serviceFor(string $category): ?Service
    {
        $keywords = self::CATEGORIES[$category]['keywords'] ?? [];

        return Service::query()->where('is_active', true)->get()->first(function (Service $s) use ($keywords) {
            $names = mb_strtolower(implode(' ', array_filter([$s->getTranslation('name', 'en', false), $s->getTranslation('name', 'ar', false)])));
            foreach ($keywords as $k) {
                if (str_contains($names, mb_strtolower($k))) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * A booking's look reference, readable for the artist: a saved Atelier design (AT…) or an AI render
     * made in the Atelier (LK…) becomes "Lashes · Cat Eye · 12 mm · C curl · 65%". Null for anything else.
     */
    public static function describeReference(?string $reference, ?string $locale = null): ?string
    {
        if (! $reference) {
            return null;
        }
        if (str_starts_with($reference, 'AT')) {
            return AtelierDesign::query()->where('reference', $reference)->first()?->localizedSummary($locale);
        }
        if (str_starts_with($reference, 'LK')) {
            $atelier = ClientLook::query()->where('reference', $reference)->value('atelier');
            $atelier = is_string($atelier) ? json_decode($atelier, true) : $atelier;
            $category = (string) data_get($atelier, 'category');

            return self::has($category) ? self::summary($category, self::normalize($category, (array) data_get($atelier, 'params')), $locale) : null;
        }

        return null;
    }

    /** What the page needs: labels in the current locale plus renderer settings. */
    public static function forClient(?string $locale = null): array
    {
        $l = ($locale ?? app()->getLocale()) === 'ar' ? 'ar' : 'en';
        $label = fn (array $set, array $keep = []) => collect($set)->map(fn ($v) => array_merge(
            ['label' => $v[$l], 'desc' => $v['desc_'.$l] ?? null],
            array_intersect_key($v, array_flip(array_merge(['hex'], $keep)))
        ))->all();
        $d = self::definition();

        return [
            'lashes' => ['styles' => $label($d['lashes']['styles'], ['profile', 'sweep', 'density', 'spikes']), 'curls' => $label($d['lashes']['curls'], ['bend']),
                'length' => $d['lashes']['length'], 'fullness' => $d['lashes']['fullness'], 'defaults' => $d['lashes']['defaults']],
            'brows' => ['shapes' => $label($d['brows']['shapes'], ['arch', 'thick', 'tail', 'lift', 'strokes']), 'shades' => $label($d['brows']['shades']),
                'intensity' => $d['brows']['intensity'], 'defaults' => $d['brows']['defaults']],
            'lips' => ['shades' => $label($d['lips']['shades']), 'finishes' => $label($d['lips']['finishes']), 'intensity' => $d['lips']['intensity'], 'defaults' => $d['lips']['defaults']],
            'nails' => ['shapes' => $label($d['nails']['shapes']), 'lengths' => $label($d['nails']['lengths'], ['ext']), 'colors' => $label($d['nails']['colors']),
                'finishes' => $label($d['nails']['finishes']), 'defaults' => $d['nails']['defaults']],
        ];
    }
}
