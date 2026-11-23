<?php

namespace App\Domain\Websites\Support;

use App\Domain\Websites\Models\PlatformPage;
use Illuminate\Support\Collection;

/**
 * Platform website content: CMS page (platform_pages) when published, otherwise built-in defaults —
 * so the marketing site is complete on day one and fully editable from /admin later.
 */
class PlatformContent
{
    public const BLOCK_TYPES = ['hero', 'logos', 'modules', 'studio_demo', 'features', 'pricing', 'testimonials', 'faq', 'cta', 'video', 'text'];

    /** @return array{page: ?PlatformPage, title: string, seo_title: ?string, seo_description: ?string, og_image: ?string, blocks: Collection<int, Block>} */
    public static function page(string $slug): array
    {
        $page = PlatformPage::published($slug);
        $raw = $page && ! empty($page->sections) ? $page->sections : (self::defaults()[$slug]['sections'] ?? []);
        $locale = app()->getLocale();

        return [
            'page' => $page,
            'title' => $page?->title ?: (self::defaults()[$slug]['title'][$locale] ?? self::defaults()[$slug]['title']['en'] ?? config('salon.name')),
            'seo_title' => $page?->getTranslation('seo_title', $locale, false) ?: null,
            'seo_description' => $page?->getTranslation('seo_description', $locale, false) ?: null,
            'og_image' => $page?->og_image_path ? asset('storage/'.$page->og_image_path) : null,
            'blocks' => collect($raw)->map(fn ($b) => new Block($b))->filter(fn (Block $b) => in_array($b->type, self::BLOCK_TYPES, true))->values(),
        ];
    }

    public static function defaults(): array
    {
        return [
            'home' => [
                'title' => ['en' => 'The luxury salon operating system', 'ar' => 'نظام تشغيل الصالونات الفاخرة'],
                'sections' => [
                    ['type' => 'hero', 'data' => [
                        'en' => ['eyebrow' => 'Salon software, reimagined', 'title' => 'Run every branch with quiet confidence.', 'body' => 'Bookings, POS, an online shop, marketing and an AI beauty studio — one elegant platform, on your own domain, in Arabic and English.', 'cta_label' => 'Start your free trial', 'cta_url' => '/signup', 'secondary_label' => 'Book a demo', 'secondary_url' => '/demo'],
                        'ar' => ['eyebrow' => 'برنامج الصالونات بمفهوم جديد', 'title' => 'أديري كل فروعك بثقة وهدوء.', 'body' => 'الحجوزات ونقطة البيع والمتجر والتسويق واستوديو الجمال الذكي — منصة واحدة أنيقة على نطاقك الخاص، بالعربية والإنجليزية.', 'cta_label' => 'ابدئي التجربة المجانية', 'cta_url' => '/signup', 'secondary_label' => 'احجزي عرضاً', 'secondary_url' => '/demo'],
                    ]],
                    ['type' => 'logos', 'data' => [
                        'en' => ['title' => 'Trusted by beauty houses across the Gulf'], 'ar' => ['title' => 'موثوق من دور التجميل في الخليج'],
                        'names' => ['Maison Lumière', 'Velvet Room', 'Atelier Noor', 'Rose & Gold', 'The Pearl Spa', 'Lustre'],
                    ]],
                    ['type' => 'modules', 'data' => [
                        'en' => ['title' => 'Everything a luxury salon needs', 'subtitle' => 'Switch modules on as you grow. Pay only for what you use.'],
                        'ar' => ['title' => 'كل ما يحتاجه صالون فاخر', 'subtitle' => 'فعّلي الوحدات مع نموك وادفعي فقط مقابل ما تستخدمينه.'],
                    ]],
                    ['type' => 'studio_demo', 'data' => [
                        'en' => ['eyebrow' => 'AI Beauty Studio', 'title' => 'Your clients see the look — then book it.', 'body' => 'Hair colour, lashes, lips and nails previewed on a selfie in seconds. Every look maps to a real service and price, so inspiration turns into revenue.'],
                        'ar' => ['eyebrow' => 'استوديو الجمال الذكي', 'title' => 'ترى عميلتك الإطلالة — ثم تحجزها.', 'body' => 'لون الشعر والرموش والشفاه والأظافر على صورة سيلفي خلال ثوانٍ. كل إطلالة مرتبطة بخدمة وسعر حقيقيين، فيتحول الإلهام إلى إيرادات.'],
                    ]],
                    ['type' => 'features', 'data' => [
                        'en' => ['title' => 'Designed for how salons really work', 'items' => [
                            ['title' => 'Multi-branch by design', 'body' => 'Branch prices, staff schedules, stock and reports — one login.'],
                            ['title' => 'Arabic & English, everywhere', 'body' => 'Full RTL websites, receipts, WhatsApp messages and dashboards.'],
                            ['title' => 'Your brand, your domain', 'body' => 'Five luxury themes driven by your colours and fonts.'],
                            ['title' => 'Local payments', 'body' => 'SADAD, SkipCash, MyFatoorah, Tap and Stripe built in.'],
                            ['title' => 'WhatsApp-first', 'body' => 'Confirmations, reminders and campaigns where clients answer.'],
                            ['title' => 'Private by default', 'body' => 'Encrypted photos, consent screens and data export on request.'],
                        ]],
                        'ar' => ['title' => 'مصمم لطريقة عمل الصالونات الحقيقية', 'items' => [
                            ['title' => 'متعدد الفروع', 'body' => 'أسعار الفروع وجداول الموظفين والمخزون والتقارير — بتسجيل دخول واحد.'],
                            ['title' => 'عربي وإنجليزي في كل مكان', 'body' => 'مواقع وإيصالات ورسائل واتساب ولوحات تحكم تدعم الاتجاه من اليمين.'],
                            ['title' => 'علامتك ونطاقك', 'body' => 'خمسة قوالب فاخرة بألوانك وخطوطك.'],
                            ['title' => 'مدفوعات محلية', 'body' => 'سداد وسكيب كاش وماي فاتورة وتاب وسترايب مدمجة.'],
                            ['title' => 'واتساب أولاً', 'body' => 'تأكيدات وتذكيرات وحملات حيث تتجاوب العميلات.'],
                            ['title' => 'خصوصية افتراضية', 'body' => 'صور مشفرة وشاشات موافقة وتصدير البيانات عند الطلب.'],
                        ]],
                    ]],
                    ['type' => 'testimonials', 'data' => [
                        'en' => ['title' => 'Loved by owners', 'items' => [
                            ['quote' => 'Our clients book the looks they try in the AI studio. It paid for itself in the first month.', 'name' => 'Noor A.', 'role' => 'Founder, Maison Lumière'],
                            ['quote' => 'Three branches, one calm dashboard. My managers finally stopped using spreadsheets.', 'name' => 'Dana K.', 'role' => 'Owner, Velvet Room'],
                            ['quote' => 'The Arabic website is genuinely beautiful — and it was live the same day.', 'name' => 'Hessa M.', 'role' => 'Director, Atelier Noor'],
                        ]],
                        'ar' => ['title' => 'يحبه أصحاب الصالونات', 'items' => [
                            ['quote' => 'عميلاتنا يحجزن الإطلالات التي جربنها في الاستوديو الذكي. استرددنا التكلفة في الشهر الأول.', 'name' => 'نور أ.', 'role' => 'مؤسسة ميزون لوميير'],
                            ['quote' => 'ثلاثة فروع ولوحة تحكم واحدة هادئة. توقفت مديراتي أخيراً عن الجداول.', 'name' => 'دانة ك.', 'role' => 'مالكة فيلفت روم'],
                            ['quote' => 'الموقع العربي جميل حقاً — وأصبح متاحاً في نفس اليوم.', 'name' => 'حصة م.', 'role' => 'مديرة أتيليه نور'],
                        ]],
                    ]],
                    ['type' => 'cta', 'data' => [
                        'en' => ['title' => 'Open your doors online today.', 'body' => '14-day free trial. No card required.', 'cta_label' => 'Start free trial', 'cta_url' => '/signup'],
                        'ar' => ['title' => 'افتحي أبوابك أونلاين اليوم.', 'body' => 'تجربة مجانية لمدة 14 يوماً بدون بطاقة.', 'cta_label' => 'ابدئي التجربة', 'cta_url' => '/signup'],
                    ]],
                ],
            ],
            'pricing' => [
                'title' => ['en' => 'Pricing', 'ar' => 'الأسعار'],
                'sections' => [
                    ['type' => 'pricing', 'data' => [
                        'en' => ['title' => 'Simple, transparent pricing', 'subtitle' => 'Choose a plan, add branches and modules — your price updates live.'],
                        'ar' => ['title' => 'أسعار بسيطة وواضحة', 'subtitle' => 'اختاري الخطة وأضيفي الفروع والوحدات — يتحدث السعر مباشرة.'],
                    ]],
                    ['type' => 'faq', 'data' => [
                        'en' => ['title' => 'Pricing questions', 'items' => [
                            ['q' => 'Is there a free trial?', 'a' => 'Yes — 14 days on any plan, no card required.'],
                            ['q' => 'Can I change plans later?', 'a' => 'Anytime. Upgrades are prorated to the day.'],
                            ['q' => 'Do longer cycles save money?', 'a' => 'Yes: 5% for 3 months, 10% for 6 months and 20% for 12 months.'],
                        ]],
                        'ar' => ['title' => 'أسئلة الأسعار', 'items' => [
                            ['q' => 'هل توجد تجربة مجانية؟', 'a' => 'نعم — 14 يوماً على أي خطة بدون بطاقة.'],
                            ['q' => 'هل يمكنني تغيير الخطة لاحقاً؟', 'a' => 'في أي وقت، مع احتساب الفرق باليوم.'],
                            ['q' => 'هل توفر الدورات الأطول المال؟', 'a' => 'نعم: 5% لثلاثة أشهر و10% لستة أشهر و20% لاثني عشر شهراً.'],
                        ]],
                    ]],
                ],
            ],
            'help' => [
                'title' => ['en' => 'Help center', 'ar' => 'مركز المساعدة'],
                'sections' => [
                    ['type' => 'faq', 'data' => [
                        'en' => ['title' => 'Getting started', 'items' => [
                            ['q' => 'How do I connect my own domain?', 'a' => 'Dashboard → Settings → Domains. Add a CNAME record and we issue SSL automatically.'],
                            ['q' => 'How do clients book online?', 'a' => 'Your website has a booking page with live availability, deposits and reminders.'],
                            ['q' => 'Which payment gateways are supported?', 'a' => 'SADAD, SkipCash, MyFatoorah, Tap and Stripe, plus cash and card terminals.'],
                            ['q' => 'How do AI credits work?', 'a' => 'Each AI look uses a few credits from your salon wallet. Plans include monthly credits and you can buy packs anytime.'],
                            ['q' => 'Can I export my data?', 'a' => 'Yes — clients, bookings and sales can be exported at any time.'],
                        ]],
                        'ar' => ['title' => 'البداية', 'items' => [
                            ['q' => 'كيف أربط نطاقي الخاص؟', 'a' => 'لوحة التحكم ← الإعدادات ← النطاقات. أضيفي سجل CNAME ونصدر شهادة SSL تلقائياً.'],
                            ['q' => 'كيف تحجز العميلات أونلاين؟', 'a' => 'يحتوي موقعك على صفحة حجز بتوفر مباشر وعربون وتذكيرات.'],
                            ['q' => 'ما بوابات الدفع المدعومة؟', 'a' => 'سداد وسكيب كاش وماي فاتورة وتاب وسترايب، إضافة إلى النقد وأجهزة البطاقات.'],
                            ['q' => 'كيف يعمل رصيد الذكاء الاصطناعي؟', 'a' => 'كل إطلالة تستخدم بضع وحدات من رصيد صالونك. تتضمن الخطط رصيداً شهرياً ويمكنك شراء باقات في أي وقت.'],
                            ['q' => 'هل يمكنني تصدير بياناتي؟', 'a' => 'نعم — يمكن تصدير العملاء والحجوزات والمبيعات في أي وقت.'],
                        ]],
                    ]],
                    ['type' => 'cta', 'data' => [
                        'en' => ['title' => 'Still need a hand?', 'body' => 'Our team answers on WhatsApp and email, in Arabic and English.', 'cta_label' => 'Talk to us', 'cta_url' => '/demo'],
                        'ar' => ['title' => 'ما زلتِ بحاجة لمساعدة؟', 'body' => 'يجيب فريقنا عبر واتساب والبريد بالعربية والإنجليزية.', 'cta_label' => 'تحدثي معنا', 'cta_url' => '/demo'],
                    ]],
                ],
            ],
            'legal-terms' => [
                'title' => ['en' => 'Terms of service', 'ar' => 'شروط الخدمة'],
                'sections' => [['type' => 'text', 'data' => [
                    'en' => ['title' => 'Terms of service', 'body' => 'These terms govern your use of '.config('salon.name').". By creating an account you agree to them.\n\nSubscriptions renew automatically at the end of each billing cycle unless cancelled. Fees are charged in advance and are non-refundable except where required by law.\n\nYou own your data. We process it only to provide the service and you may export or delete it at any time.\n\nWe may suspend accounts that are unpaid after the grace period or that misuse the platform."],
                    'ar' => ['title' => 'شروط الخدمة', 'body' => 'تحكم هذه الشروط استخدامك لـ '.config('salon.name').". بإنشاء حساب فإنك توافقين عليها.\n\nتتجدد الاشتراكات تلقائياً في نهاية كل دورة فوترة ما لم يتم إلغاؤها. تُدفع الرسوم مقدماً وغير قابلة للاسترداد إلا حيث يقتضي القانون.\n\nأنتِ تملكين بياناتك. نعالجها فقط لتقديم الخدمة ويمكنك تصديرها أو حذفها في أي وقت.\n\nقد نعلّق الحسابات غير المسددة بعد فترة السماح أو التي تسيء استخدام المنصة."],
                ]]],
            ],
            'legal-privacy' => [
                'title' => ['en' => 'Privacy policy', 'ar' => 'سياسة الخصوصية'],
                'sections' => [['type' => 'text', 'data' => [
                    'en' => ['title' => 'Privacy policy', 'body' => "We collect only what is needed to run your salon software: account details, your salon's data and usage analytics.\n\nAI Beauty Studio photos are encrypted, used only to create the requested preview, never used to train AI models, and deleted automatically unless the client saves the look.\n\nWe follow GDPR principles and Qatar's Personal Data Privacy Protection Law (PDPPL). You can request access, export or deletion of personal data at any time."],
                    'ar' => ['title' => 'سياسة الخصوصية', 'body' => "نجمع فقط ما يلزم لتشغيل برنامج صالونك: بيانات الحساب وبيانات صالونك وتحليلات الاستخدام.\n\nصور استوديو الجمال الذكي مشفرة وتستخدم فقط لإنشاء المعاينة المطلوبة، ولا تُستخدم أبداً لتدريب نماذج الذكاء الاصطناعي، وتُحذف تلقائياً ما لم تحفظ العميلة الإطلالة.\n\nنتبع مبادئ اللائحة الأوروبية وقانون حماية خصوصية البيانات الشخصية في قطر. يمكنك طلب الوصول إلى البيانات الشخصية أو تصديرها أو حذفها في أي وقت."],
                ]]],
            ],
        ];
    }
}
