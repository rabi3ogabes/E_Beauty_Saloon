<?php

namespace Database\Seeders\Demo;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Websites\Actions\EnsureHomePage;
use App\Domain\Websites\Models\BlogPost;
use App\Domain\Websites\Models\PlatformPage;
use App\Domain\Websites\Models\SitePage;
use App\Domain\Websites\Support\PlatformContent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/** Platform CMS defaults + 3 journal posts, and E Beauty Saloon's customized website (sections, SEO, settings). */
class WebsiteDemoSeeder extends Seeder
{
    public static int $order = 60;

    public function run(): void
    {
        $i = 0;
        foreach (PlatformContent::defaults() as $slug => $page) {
            PlatformPage::query()->firstOrCreate(['slug' => $slug], [
                'title' => $page['title'], 'sections' => $page['sections'], 'is_published' => true, 'sort' => $i++,
                'seo_title' => $slug === 'home' ? ['en' => 'Luxury salon software — booking, POS & AI Studio', 'ar' => 'برنامج الصالونات الفاخرة — الحجز ونقطة البيع والاستوديو الذكي'] : null,
            ]);
        }

        $posts = [
            ['ai-try-on-turns-inspiration-into-bookings', 'How AI try-on turns inspiration into bookings', 'كيف يحوّل التجريب بالذكاء الاصطناعي الإلهام إلى حجوزات',
                'Clients arrive with screenshots. The AI Beauty Studio lets them see the look on themselves — and book it in one tap.',
                'تصل العميلات بلقطات شاشة. يتيح استوديو الجمال الذكي رؤية الإطلالة عليهن — وحجزها بلمسة.', 6],
            ['the-luxury-salon-website-checklist', 'The luxury salon website checklist', 'قائمة مراجعة موقع الصالون الفاخر',
                'Speed, bilingual content and local SEO matter more than ever. Here is what the best salon websites in the Gulf get right.',
                'السرعة والمحتوى ثنائي اللغة والسيو المحلي أهم من أي وقت. إليك ما تتقنه أفضل مواقع الصالونات في الخليج.', 12],
            ['reduce-no-shows-with-whatsapp', 'Reduce no-shows with WhatsApp reminders and deposits', 'قللي حالات الغياب بتذكيرات واتساب والعربون',
                'A friendly reminder 24 hours before, a small deposit for long services and a one-tap reschedule link cut no-shows dramatically.',
                'تذكير لطيف قبل 24 ساعة وعربون بسيط للخدمات الطويلة ورابط تعديل بلمسة واحدة تقلل الغياب بشكل كبير.', 20],
        ];
        foreach ($posts as [$slug, $en, $ar, $exEn, $exAr, $daysAgo]) {
            BlogPost::query()->firstOrCreate(['slug' => $slug], [
                'title' => ['en' => $en, 'ar' => $ar],
                'excerpt' => ['en' => $exEn, 'ar' => $exAr],
                'body' => [
                    'en' => "<p>{$exEn}</p><h2>Why it matters</h2><p>Luxury clients expect effortless, personal experiences — online as much as in the chair. Small details compound: a fast page, the right language, a confident price and a clear next step.</p><h2>What to do this week</h2><ul><li>Audit your top three services and their online path to booking.</li><li>Make every page bilingual and mobile-first.</li><li>Measure, then refine.</li></ul><blockquote>The best salons treat their website like their reception desk.</blockquote>",
                    'ar' => "<p>{$exAr}</p><h2>لماذا يهم ذلك</h2><p>تتوقع العميلات في القطاع الفاخر تجربة سلسة وشخصية — أونلاين كما على الكرسي. التفاصيل الصغيرة تتراكم: صفحة سريعة ولغة مناسبة وسعر واضح وخطوة تالية واضحة.</p><h2>ما الذي تفعلينه هذا الأسبوع</h2><ul><li>راجعي أهم ثلاث خدمات ومسار حجزها أونلاين.</li><li>اجعلي كل صفحة ثنائية اللغة ومناسبة للجوال.</li><li>قيسي ثم حسّني.</li></ul><blockquote>أفضل الصالونات تعامل موقعها كما تعامل مكتب الاستقبال.</blockquote>",
                ],
                'author_name' => 'Luxe Salon OS',
                'tags' => ['growth', 'website'],
                'published_at' => now()->subDays($daysAgo),
            ]);
        }

        $tenant = Tenant::where('slug', 'lumiere')->first();
        if (! $tenant) {
            return;
        }
        app(TenantContext::class)->run($tenant, fn () => $this->seedTenant($tenant));
    }

    protected function seedTenant(Tenant $tenant): void
    {
        if (SitePage::query()->exists()) {
            return;
        }
        $settings = $tenant->settings ?? [];
        $settings['website'] = array_merge($settings['website'] ?? [], [
            'seo_description' => 'E Beauty Saloon — luxury hair, nails, lashes and skin rituals in West Bay and The Pearl, Doha. Book online in seconds.',
            'gbp_booking_url' => 'https://www.google.com/maps/reserve',
        ]);
        $tenant->update(['settings' => $settings]);

        $home = app(EnsureHomePage::class)->handle($tenant);
        $home->update(['seo_title' => ['en' => 'Luxury beauty salon in Doha', 'ar' => 'صالون تجميل فاخر في الدوحة'],
            'seo_description' => ['en' => 'Couture hair, flawless nails and skin rituals in West Bay and The Pearl.', 'ar' => 'تسريحات راقية وأظافر مثالية وطقوس للبشرة في الخليج الغربي واللؤلؤة.']]);
        $home->sections()->where('type', 'hero')->first()?->update(['content' => [
            'en' => ['eyebrow' => 'Doha · Since 2014', 'title' => 'The art of considered beauty', 'subtitle' => 'Couture hair, flawless nails and skin rituals by an award-winning team — in West Bay and The Pearl.', 'cta_label' => 'Book your ritual'],
            'ar' => ['eyebrow' => 'الدوحة · منذ 2014', 'title' => 'فن الجمال المدروس', 'subtitle' => 'تسريحات راقية وأظافر مثالية وطقوس للبشرة على يد فريق حائز على جوائز — في الخليج الغربي واللؤلؤة.', 'cta_label' => 'احجزي طقوسك'],
        ]]);
        $sort = (int) $home->sections()->max('sort');
        $home->sections()->create(['type' => 'instagram', 'sort' => $sort + 1, 'settings' => ['handle' => 'ebeautysaloon'],
            'content' => ['en' => ['title' => 'Life at E Beauty'], 'ar' => ['title' => 'الحياة في إي بيوتي']]]);

        $bridal = SitePage::create(['slug' => 'bridal', 'title' => ['en' => 'Bridal', 'ar' => 'العرائس'], 'is_published' => true, 'in_menu' => true, 'sort' => 1,
            'seo_title' => ['en' => 'Bridal hair & makeup in Doha', 'ar' => 'تسريحات ومكياج العرائس في الدوحة']]);
        $bridal->sections()->create(['type' => 'hero', 'sort' => 0, 'content' => [
            'en' => ['eyebrow' => 'Bridal', 'title' => 'Your day, beautifully composed', 'subtitle' => 'Trials, on-location teams and a calm, private suite for you and your bridal party.', 'cta_label' => 'Plan my bridal look'],
            'ar' => ['eyebrow' => 'العرائس', 'title' => 'يومك بتفاصيل أنيقة', 'subtitle' => 'جلسات تجريبية وفريق متنقل وجناح خاص هادئ لكِ ولمرافقاتك.', 'cta_label' => 'خططي لإطلالتك'],
        ]]);
        $bridal->sections()->create(['type' => 'studio_cta', 'sort' => 1, 'content' => [
            'en' => ['title' => 'Preview your bridal look', 'body' => 'Try Arabic and modern bridal makeup on your own photo before your trial.'],
            'ar' => ['title' => 'شاهدي إطلالة عرسك', 'body' => 'جربي مكياج العروس العربي والعصري على صورتك قبل الجلسة التجريبية.'],
        ]]);
        $bridal->sections()->create(['type' => 'contact', 'sort' => 2, 'content' => [
            'en' => ['title' => 'Bridal enquiries', 'body' => 'Tell us your date and we will craft your timeline.'],
            'ar' => ['title' => 'استفسارات العرائس', 'body' => 'أخبرينا بموعدك وسنعد لك الجدول الزمني.'],
        ]]);
    }
}
