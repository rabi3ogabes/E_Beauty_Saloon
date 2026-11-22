<?php

namespace App\Domain\Websites\Support;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Websites\Models\SiteSection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;

/**
 * Salon website section types: their translatable fields, list fields, availability and the
 * built-in defaults used when an owner has not customized the site yet.
 */
class SectionRegistry
{
    /** type => [fields (per locale), list fields (per locale), module gate] */
    public const TYPES = [
        'hero' => ['fields' => ['eyebrow', 'title', 'subtitle', 'cta_label'], 'module' => null],
        'services' => ['fields' => ['title', 'subtitle'], 'module' => null],
        'team' => ['fields' => ['title', 'subtitle'], 'module' => null],
        'gallery' => ['fields' => ['title', 'subtitle'], 'module' => null],
        'studio_cta' => ['fields' => ['eyebrow', 'title', 'body', 'cta_label'], 'module' => 'ai_studio'],
        'shop' => ['fields' => ['title', 'subtitle'], 'module' => 'shop'],
        'packages' => ['fields' => ['title', 'subtitle'], 'module' => null],
        'reviews' => ['fields' => ['title', 'subtitle'], 'module' => null],
        'map' => ['fields' => ['title', 'subtitle'], 'module' => null],
        'instagram' => ['fields' => ['title', 'subtitle'], 'module' => null],
        'faq' => ['fields' => ['title'], 'lists' => ['items'], 'module' => null],
        'contact' => ['fields' => ['title', 'body'], 'module' => null],
        'text' => ['fields' => ['eyebrow', 'title', 'body'], 'module' => null],
    ];

    public static function options(): array
    {
        return collect(self::TYPES)->keys()->mapWithKeys(fn ($t) => [$t => __('website.sections.'.$t)])->all();
    }

    /** Whether a section can render for this tenant (module gates, optional components from other modules). */
    public static function available(SiteSection $section, ?Tenant $tenant): bool
    {
        $type = self::TYPES[$section->type] ?? null;
        if (! $type || ! $section->is_visible) {
            return false;
        }
        if ($type['module'] && ! $tenant?->hasModule($type['module'])) {
            return false;
        }

        return match ($section->type) {
            'reviews' => View::exists('components.marketing.reviews'),
            'packages' => View::exists('components.packages.highlights'),
            default => true,
        };
    }

    /** Built-in home page sections (unsaved models) for salons that have not used the builder yet. */
    public static function defaultHome(?Tenant $tenant = null): Collection
    {
        $name = $tenant?->name ?? '';
        $rows = [
            ['hero', [
                'en' => ['eyebrow' => 'Beauty, beautifully done', 'title' => $name, 'subtitle' => 'Couture hair, flawless nails and skin rituals by an award-winning team.', 'cta_label' => 'Book your visit'],
                'ar' => ['eyebrow' => 'جمالك بأرقى التفاصيل', 'title' => $name, 'subtitle' => 'تسريحات راقية وأظافر مثالية وطقوس للبشرة على يد فريق حائز على جوائز.', 'cta_label' => 'احجزي زيارتك'],
            ]],
            ['services', [
                'en' => ['title' => 'The menu', 'subtitle' => 'Thoughtful rituals, transparent prices.'],
                'ar' => ['title' => 'قائمة الخدمات', 'subtitle' => 'طقوس جمال مدروسة وأسعار واضحة.'],
            ]],
            ['studio_cta', [
                'en' => ['eyebrow' => 'AI Beauty Studio', 'title' => 'See the look before you book it', 'body' => 'Preview hair colour, lashes, lips and nails on your own photo — every look links to a real service.', 'cta_label' => 'Try a look'],
                'ar' => ['eyebrow' => 'استوديو الجمال الذكي', 'title' => 'شاهدي الإطلالة قبل أن تحجزيها', 'body' => 'جرّبي لون الشعر والرموش والشفاه والأظافر على صورتك — كل إطلالة مرتبطة بخدمة حقيقية.', 'cta_label' => 'جرّبي إطلالة'],
            ]],
            ['team', [
                'en' => ['title' => 'Meet the artists', 'subtitle' => 'Senior specialists, each a master of their craft.'],
                'ar' => ['title' => 'تعرّفي على خبيراتنا', 'subtitle' => 'خبيرات متمرسات، كل واحدة متقنة لفنها.'],
            ]],
            ['gallery', [
                'en' => ['title' => 'Recent work', 'subtitle' => 'A glimpse of the transformations our clients love.'],
                'ar' => ['title' => 'من أعمالنا', 'subtitle' => 'لمحة من التحولات التي تحبها عميلاتنا.'],
            ]],
            ['shop', [
                'en' => ['title' => 'The boutique', 'subtitle' => 'Professional care, chosen by our artists.'],
                'ar' => ['title' => 'البوتيك', 'subtitle' => 'منتجات احترافية اختارتها خبيراتنا.'],
            ]],
            ['reviews', [
                'en' => ['title' => 'Kind words', 'subtitle' => null],
                'ar' => ['title' => 'كلمات لطيفة', 'subtitle' => null],
            ]],
            ['map', [
                'en' => ['title' => 'Visit us', 'subtitle' => 'Find the salon closest to you.'],
                'ar' => ['title' => 'زورينا', 'subtitle' => 'اعثري على أقرب صالون إليك.'],
            ]],
            ['faq', [
                'en' => ['title' => 'Good to know', 'items' => [
                    ['q' => 'How do I book?', 'a' => 'Book online in under a minute, call us or message us on WhatsApp.'],
                    ['q' => 'What is your cancellation policy?', 'a' => 'Free changes up to 24 hours before your appointment.'],
                    ['q' => 'Do you offer gift cards?', 'a' => 'Yes — digital gift cards are delivered instantly by WhatsApp or email.'],
                ]],
                'ar' => ['title' => 'معلومات تهمك', 'items' => [
                    ['q' => 'كيف أحجز؟', 'a' => 'احجزي أونلاين في أقل من دقيقة، أو اتصلي بنا، أو راسلينا عبر واتساب.'],
                    ['q' => 'ما سياسة الإلغاء؟', 'a' => 'التعديل مجاني حتى 24 ساعة قبل الموعد.'],
                    ['q' => 'هل لديكم بطاقات هدايا؟', 'a' => 'نعم — تصل بطاقات الهدايا الرقمية فوراً عبر واتساب أو البريد.'],
                ]],
            ]],
            ['contact', [
                'en' => ['title' => 'We would love to see you', 'body' => 'Questions, bridal enquiries or private events — reach out any time.'],
                'ar' => ['title' => 'يسعدنا لقاؤك', 'body' => 'للاستفسارات أو حجوزات العرائس أو المناسبات الخاصة — تواصلي معنا في أي وقت.'],
            ]],
        ];

        return collect($rows)->values()->map(fn ($row, $i) => new SiteSection([
            'type' => $row[0], 'content' => $row[1], 'settings' => [], 'is_visible' => true, 'sort' => $i,
        ]));
    }
}
