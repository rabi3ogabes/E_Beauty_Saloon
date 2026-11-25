<?php

namespace App\Domain\AIStudio\Support;

/**
 * Curated example looks bundled with the platform (resources/studio-examples). Photos are photorealistic
 * illustrations generated for the demo salon, so looks installed from here are flagged `illustrative` —
 * salons replace them with their own work from Dashboard → Style library.
 *
 * key => [category, option, en, ar, description en, description ar, prompt, service slug, swatch, photos]
 */
final class ExampleLooks
{
    public const SOURCE_DIR = 'resources/studio-examples';

    public const TARGET_DIR = 'ai-styles/examples';

    /** Services the extension looks book into (created for the demo salon when missing). [slug, en, ar, minutes, price] */
    public const EXTENSION_SERVICES = [
        ['tape-in-extensions', 'Tape-in extensions', 'وصلات شعر لاصقة', 150, 180000],
        ['clip-in-extensions', 'Clip-in extensions fitting', 'تركيب وصلات بالمشابك', 45, 45000],
        ['keratin-bond-extensions', 'Keratin bond extensions', 'وصلات شعر بالكيراتين', 240, 350000],
        ['extension-ponytail', 'Extension ponytail', 'ذيل حصان بالوصلات', 60, 60000],
    ];

    public static function all(): array
    {
        return [
            // Nails
            'rouge-noir-gel' => ['nails', 'color', 'Rouge noir gel', 'جل روج نوار', 'Deep burgundy-black gel on medium almond nails — glossy, timeless, quietly dramatic.', 'جل عنابي داكن يميل إلى الأسود على أظافر لوزية متوسطة — لامع وأنيق.', 'Glossy rouge-noir gel polish on medium almond nails.', 'gel-manicure', '#3A0F1A', 2],
            'milky-nude' => ['nails', 'color', 'Milky nude', 'نود حليبي', 'A sheer milky nude on short squoval nails — the clean, polished everyday finish.', 'نود حليبي شفاف على أظافر قصيرة — لمسة نظيفة وأنيقة لكل يوم.', 'Milky sheer nude gel on short squoval nails, clean-girl finish.', 'gel-manicure', '#F1DCD3', 2],
            'gold-chrome-french' => ['nails', 'art', 'Gold chrome French', 'فرنش كروم ذهبي', 'A whisper-thin gold chrome French line over a nude almond base.', 'خط فرنش ذهبي كروم رفيع فوق قاعدة نود على أظافر لوزية.', 'Fine gold chrome French tips on nude almond nails.', 'nail-art-per-set', '#C8A96A', 2],
            'coral-almond' => ['nails', 'shape', 'Coral almond', 'لوز مرجاني', 'Long almond acrylics in a glossy coral bloom — bright, sculpted and summer-ready.', 'أظافر أكريليك لوزية طويلة بلون مرجاني لامع — مشرقة وصيفية.', 'Long almond-shaped acrylic nails in glossy coral bloom.', 'acrylic-extensions', '#E9765B', 2],
            'baby-boomer-ombre' => ['nails', 'art', 'Baby-boomer ombré', 'أومبريه بيبي بومر', 'Soft pink at the cuticle melting into milky white tips — the seamless French.', 'وردي ناعم عند الجذور يذوب في أطراف بيضاء حليبية — فرنش متدرج.', 'Baby boomer ombre nails: soft pink at the cuticle fading seamlessly into milky white tips, glossy, medium almond.', 'nail-art-per-set', '#F4D9D6', 2],
            'pearl-glazed-chrome' => ['nails', 'art', 'Pearl glazed chrome', 'كروم لؤلؤي', 'An iridescent pearl glaze over sheer white — catches the light like a pearl.', 'لمعة لؤلؤية متقزحة فوق قاعدة بيضاء شفافة — تتلألأ كاللؤلؤ.', 'Pearl glazed chrome nails: sheer white base with iridescent pearly sheen, short rounded shape.', 'gel-manicure', '#EFE8E2', 2],
            // Pedicure
            'classic-red-toes' => ['pedicure', 'color', 'Classic red toes', 'باديكير أحمر', 'A glossy classic red with neat cuticles — the pedicure that never goes out of style.', 'أحمر كلاسيكي لامع مع جلد نظيف — باديكير لا يخرج عن الموضة.', 'Classic glossy red pedicure, neat cuticles.', 'gel-pedicure', '#A4262C', 2],
            'champagne-shimmer' => ['pedicure', 'color', 'Champagne shimmer', 'لمعة الشمبانيا', 'Champagne-gold shimmer under a high-gloss top coat — glowing on sandals.', 'لمعة شمبانيا ذهبية تحت طبقة لامعة — تتألق مع الصنادل.', 'Champagne shimmer pedicure with a high-gloss top coat.', 'spa-pedicure', '#E8CFA8', 2],
            'french-pedicure' => ['pedicure', 'art', 'French pedicure', 'باديكير فرنش', 'Clean white tips over a sheer pink base — crisp, bridal-ready, effortless.', 'أطراف بيضاء نظيفة فوق قاعدة وردية شفافة — أنيق ومثالي للعرائس.', 'Classic French pedicure: crisp white tips over sheer pink base.', 'gel-pedicure', '#F6EEE9', 2],
            'nude-gold-pedicure' => ['pedicure', 'art', 'Nude with gold accent', 'نود مع لمسة ذهبية', 'A warm nude with a single gold-leaf accent on the big toe — understated luxury.', 'نود دافئ مع لمسة ورق ذهبي على الإصبع الكبير — فخامة هادئة.', 'Warm nude pedicure with a small gold leaf accent on the big toe.', 'spa-pedicure', '#D9B8A0', 2],
            // Hair extensions
            'tape-in-length' => ['extensions', 'length', 'Tape-in length', 'طول بالوصلات اللاصقة', 'Waist-length, seamlessly blended tape-ins — instant length that moves like your own hair.', 'طول حتى الخصر بوصلات لاصقة مدمجة بسلاسة — طول فوري يتحرك كشعرك الطبيعي.', 'Seamless tape-in hair extensions adding waist length, blended invisibly with natural hair, soft waves at the ends.', 'tape-in-extensions', '#6B4A33', 2],
            'clip-in-volume' => ['extensions', 'volume', 'Clip-in volume', 'كثافة بالمشابك', 'Clip-ins for fuller, bouncier hair on the nights you want more — removable in minutes.', 'وصلات بالمشابك لشعر أكثف وحيوية في المناسبات — تُزال في دقائق.', 'Clip-in extensions adding fullness and volume, bouncy blow-dry finish, shoulder-blade length.', 'clip-in-extensions', '#3B2418', 2],
            'keratin-bond-layers' => ['extensions', 'length', 'Keratin-bond long layers', 'طبقات طويلة بالكيراتين', 'Strand-by-strand keratin bonds for long, layered, natural-looking length that lasts months.', 'وصلات كيراتين خصلة بخصلة لطول متدرج طبيعي يدوم لأشهر.', 'Keratin bond hair extensions creating long face-framing layers, natural movement, glossy finish.', 'keratin-bond-extensions', '#8A6240', 2],
            'sleek-extension-ponytail' => ['extensions', 'ponytail', 'Sleek extension ponytail', 'ذيل حصان أنيق بالوصلات', 'A high, glass-sleek ponytail with added length — red-carpet polish for events.', 'ذيل حصان مرتفع ولامع مع طول إضافي — أناقة السجادة الحمراء للمناسبات.', 'High sleek glossy ponytail with long extensions, wrapped base, smooth crown.', 'extension-ponytail', '#2A1A12', 2],
        ];
    }

    /** Bundled file names for a look: [key-1.webp, key-2.webp, …]. */
    public static function files(string $key): array
    {
        return array_map(fn (int $n) => "{$key}-{$n}.webp", range(1, self::all()[$key][9]));
    }
}
