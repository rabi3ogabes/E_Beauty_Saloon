<?php

namespace Database\Seeders\Demo;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Models\Tag;
use App\Domain\Marketing\Actions\InstallAutomationRecipes;
use App\Domain\Marketing\Models\Banner;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Domain\Marketing\Models\ClientMembership;
use App\Domain\Marketing\Models\ClientPackage;
use App\Domain\Marketing\Models\Membership;
use App\Domain\Marketing\Models\Package;
use App\Domain\Marketing\Models\PlatformMessageChannel;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Models\Referral;
use App\Domain\Marketing\Models\Review;
use App\Domain\Marketing\Models\Segment;
use App\Domain\Marketing\Models\TenantChannelSetting;
use App\Domain\Marketing\Models\WhatsappTemplate;
use App\Domain\Marketing\Services\MessageCredits;
use App\Domain\Marketing\Support\LoyaltySettings;
use App\Domain\Team\Models\Staff;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Marketing, loyalty & reviews demo data for "E Beauty Saloon": packages, a membership, promotions, 3 audiences,
 * 3 past campaigns with realistic funnels and attributed bookings, automations, ~40 reviews.
 */
class MarketingDemoSeeder extends Seeder
{
    public static int $order = 50;

    public function run(): void
    {
        PlatformMessageChannel::updateOrCreate(['channel' => 'whatsapp'], ['provider' => 'meta', 'price_per_message' => 15, 'is_active' => false]);
        PlatformMessageChannel::updateOrCreate(['channel' => 'sms'], ['provider' => 'unifonic', 'price_per_message' => 10, 'is_active' => false]);

        $tenant = Tenant::where('slug', 'lumiere')->first();
        if (! $tenant || app(TenantContext::class)->run($tenant, fn () => Campaign::exists())) {
            return;
        }

        app(TenantContext::class)->run($tenant, fn () => $this->seedTenant($tenant));
    }

    protected function seedTenant(Tenant $tenant): void
    {
        $faker = fake();
        mt_srand(42);
        $settings = $tenant->settings ?? [];
        data_set($settings, 'loyalty', ['enabled' => true, 'points_per_currency_unit' => 1, 'point_value' => 5, 'min_redeem_points' => 100,
            'tiers' => LoyaltySettings::DEFAULT_TIERS, 'referral' => ['referrer_points' => 500, 'friend_discount_bp' => 1500, 'friend_coupon_days' => 60]]);
        data_set($settings, 'marketing', ['quiet_hours' => ['enabled' => true, 'start' => '21:00', 'end' => '09:00'], 'rate_per_minute' => 600]);
        $tenant->update(['settings' => $settings]);

        TenantChannelSetting::create(['channel' => 'sms', 'mode' => 'platform_shared', 'is_active' => false]);
        app(MessageCredits::class)->topUp($tenant->id, 50000, 'Welcome credits', 'demo:topup:'.$tenant->id);

        $services = Service::all()->keyBy('slug');
        $branches = Branch::all();
        $clients = Client::query()->orderBy('id')->get();
        $staff = Staff::query()->get();

        // ── Packages & membership ──────────────────────────────────────────
        $bridal = Package::create(['name' => ['en' => 'Bridal Glow', 'ar' => 'إشراقة العروس'], 'slug' => 'bridal-glow',
            'description' => ['en' => 'Bridal hair, bridal makeup and a gel manicure — the complete wedding-day ritual.', 'ar' => 'تسريحة ومكياج العروس مع مانيكير جل — طقوس يوم الزفاف كاملة.'],
            'pricing' => 'percent', 'discount_bp' => 1500, 'sessions_count' => 3, 'validity_days' => 120, 'sort' => 0]);
        foreach (['bridal-hair', 'bridal-makeup', 'gel-manicure'] as $slug) {
            if ($s = $services[$slug] ?? null) {
                $bridal->items()->create(['itemable_type' => $s->getMorphClass(), 'itemable_id' => $s->id, 'quantity' => 1]);
            }
        }
        $blowdries = Package::create(['name' => ['en' => '6 Signature blow-dries', 'ar' => '6 جلسات استشوار سيغنتشر'], 'slug' => 'six-blowdries',
            'description' => ['en' => 'Six signature blow-dries to use within 3 months.', 'ar' => 'ست جلسات استشوار خلال 3 أشهر.'],
            'pricing' => 'fixed', 'price' => 75000, 'sessions_count' => 6, 'validity_days' => 90, 'sort' => 1]);
        if ($s = $services['signature-blow-dry'] ?? null) {
            $blowdries->items()->create(['itemable_type' => $s->getMorphClass(), 'itemable_id' => $s->id, 'quantity' => 6]);
        }
        $spa = Package::create(['name' => ['en' => 'Spa Sanctuary', 'ar' => 'ملاذ السبا'], 'slug' => 'spa-sanctuary',
            'pricing' => 'percent', 'discount_bp' => 2000, 'sessions_count' => 3, 'validity_days' => 180, 'sort' => 2]);
        foreach (['moroccan-bath', 'hot-stone-massage', 'gold-radiance-facial'] as $slug) {
            if ($s = $services[$slug] ?? null) {
                $spa->items()->create(['itemable_type' => $s->getMorphClass(), 'itemable_id' => $s->id, 'quantity' => 1]);
            }
        }
        foreach ($clients->random(14) as $i => $client) {
            $pkg = $i % 2 ? $blowdries : $spa;
            $remaining = $i % 5;
            ClientPackage::create(['client_id' => $client->id, 'package_id' => $pkg->id, 'status' => $remaining ? 'active' : 'used_up',
                'sessions_total' => $pkg->sessions_count, 'sessions_remaining' => min($remaining, $pkg->sessions_count), 'price_paid' => $pkg->price ?: 120000,
                'channel' => $i % 3 ? 'pos' : 'online', 'activated_at' => now()->subDays(20 + $i * 3), 'expires_at' => now()->addDays(60 - $i * 3)]);
        }

        $club = Membership::create(['name' => ['en' => 'E Beauty Club', 'ar' => 'نادي إي بيوتي'], 'monthly_price' => 59900, 'billing' => 'recurring',
            'description' => ['en' => '2 signature blow-dries every month, 10% off products and priority booking.', 'ar' => 'جلستا استشوار كل شهر، خصم 10% على المنتجات وأولوية في الحجز.'],
            'perks' => ['included' => array_values(array_filter([($services['signature-blow-dry'] ?? null) ? ['service_id' => $services['signature-blow-dry']->id, 'quantity' => 2] : null])), 'product_discount_bp' => 1000, 'service_discount_bp' => 0]]);
        foreach ($clients->random(9) as $i => $client) {
            ClientMembership::create(['client_id' => $client->id, 'membership_id' => $club->id, 'status' => 'active', 'starts_at' => now()->subMonths(1 + $i % 4),
                'renews_at' => now()->addDays(2 + $i * 3), 'auto_renew' => true, 'perks_used' => []]);
        }

        // ── Promotions ─────────────────────────────────────────────────────
        Promotion::create(['name' => ['en' => 'Glow 15', 'ar' => 'إشراقة 15'], 'type' => 'coupon', 'code' => 'GLOW15', 'discount_type' => 'percent', 'discount_value' => 1500, 'usage_limit' => 300, 'used_count' => 47, 'per_client_limit' => 1]);
        Promotion::create(['name' => ['en' => 'Morning ritual', 'ar' => 'طقوس الصباح'], 'type' => 'happy_hour', 'discount_type' => 'percent', 'discount_value' => 2000,
            'rules' => ['weekdays' => [0, 1, 2, 3], 'from' => '10:00', 'to' => '13:00', 'applies_to' => 'services']]);
        Promotion::create(['name' => ['en' => 'Welcome to E Beauty', 'ar' => 'أهلاً بكِ في إي بيوتي'], 'type' => 'first_visit', 'discount_type' => 'percent', 'discount_value' => 1000, 'rules' => ['applies_to' => 'services']]);
        Promotion::create(['name' => ['en' => 'Birthday treat', 'ar' => 'هدية عيد الميلاد'], 'type' => 'birthday', 'discount_type' => 'percent', 'discount_value' => 2000, 'rules' => ['days_before' => 7, 'days_after' => 7]]);
        Promotion::create(['name' => ['en' => 'White Friday flash sale', 'ar' => 'تخفيضات الجمعة البيضاء'], 'type' => 'flash_sale', 'discount_type' => 'percent', 'discount_value' => 2500,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3), 'show_countdown' => true, 'rules' => ['applies_to' => 'products']]);
        Promotion::create(['name' => ['en' => 'Polish trio', 'ar' => 'ثلاثية طلاء الأظافر'], 'type' => 'bogo', 'rules' => ['applies_to' => 'products', 'buy_quantity' => 2, 'get_quantity' => 1, 'get_discount_bp' => 10000]]);

        // ── WhatsApp templates & banners ───────────────────────────────────
        $tplEn = WhatsappTemplate::create(['name' => 'seasonal_offer', 'language' => 'en', 'category' => 'MARKETING', 'status' => 'approved', 'header_type' => 'image',
            'body' => "Hi {{1}} ✦ Our new season rituals are here. Reserve your moment: {{2}}\n\n— {{3}}", 'footer' => 'Reply STOP to opt out',
            'buttons' => [['type' => 'url', 'text' => 'Book now', 'url' => 'https://lumiere.localhost/c/{{1}}']], 'synced_at' => now()->subDay(), 'provider_template_id' => '1029384756']);
        WhatsappTemplate::create(['name' => 'seasonal_offer', 'language' => 'ar', 'category' => 'MARKETING', 'status' => 'approved', 'header_type' => 'image',
            'body' => "مرحباً {{1}} ✦ وصلت طقوس الموسم الجديد. احجزي لحظتك: {{2}}\n\n— {{3}}", 'footer' => 'أرسلي STOP لإيقاف الرسائل',
            'buttons' => [['type' => 'url', 'text' => 'احجزي الآن', 'url' => 'https://lumiere.localhost/c/{{1}}']], 'synced_at' => now()->subDay(), 'provider_template_id' => '1029384757']);
        WhatsappTemplate::create(['name' => 'we_miss_you', 'language' => 'en', 'category' => 'MARKETING', 'status' => 'pending', 'body' => 'We miss you, {{1}}. Here is 15% off your next visit: {{2}}']);
        $banner = Banner::create(['name' => 'The Golden Hour', 'format' => 'whatsapp', 'template' => 'editorial']);

        // ── Audiences ──────────────────────────────────────────────────────
        $lapsed = Segment::create(['name' => 'Lapsed 60+ days', 'description' => 'Clients who have not visited in two months', 'rules' => ['match' => 'all', 'conditions' => [['field' => 'not_visited_days', 'value' => 60]]]]);
        $vip = Segment::create(['name' => 'VIP & big spenders', 'rules' => ['match' => 'any', 'conditions' => [['field' => 'vip'], ['field' => 'spent_over', 'value' => 8000]]]]);
        $birthdays = Segment::create(['name' => 'Birthdays this month', 'rules' => ['match' => 'all', 'conditions' => [['field' => 'birthday_this_month']]]]);
        foreach ([$lapsed, $vip, $birthdays] as $segment) {
            $segment->refreshCount();
        }

        // ── Past campaigns with realistic funnels ──────────────────────────
        $catalog = [
            [$vip, 'White Friday — VIP preview', ['whatsapp', 'sms'], 21, 0.97, 0.88, 0.71, 0.24, 0.09, $tplEn->id],
            [$lapsed, 'We miss you — 15% off', ['whatsapp', 'sms', 'email'], 35, 0.94, 0.81, 0.58, 0.16, 0.06, null],
            [$segmentAll = Segment::create(['name' => 'Everyone who opted in', 'rules' => ['match' => 'all', 'conditions' => [['field' => 'has_consent', 'value' => 'whatsapp']]]]), 'Ramadan glow rituals', ['whatsapp', 'email'], 12, 0.98, 0.9, 0.76, 0.19, 0.07, $tplEn->id],
        ];
        $segmentAll->refreshCount();
        foreach ($catalog as [$segment, $name, $channels, $daysAgo, $sentRate, $deliveredRate, $readRate, $clickRate, $bookRate, $tplId]) {
            $started = now()->subDays($daysAgo)->setTime(10, 30);
            $campaign = Campaign::create([
                'name' => $name, 'segment_id' => $segment->id, 'channels' => $channels, 'whatsapp_template_id' => $tplId, 'banner_id' => $banner->id,
                'content' => ['en' => ['subject' => $name, 'body' => "Hi {first_name} ✦ {$name} at E Beauty Saloon. Reserve: {link}"], 'ar' => ['body' => 'مرحباً {first_name} ✦ احجزي الآن: {link}']],
                'status' => 'sent', 'started_at' => $started, 'completed_at' => $started->copy()->addMinutes(18),
            ]);
            $audience = $segment->clients()->with('consents')->get();
            $sentCount = 0;
            foreach ($audience as $client) {
                $consented = collect($channels)->first(fn ($ch) => $client->consents->firstWhere('channel', $ch)?->granted && ($ch !== 'email' || $client->email));
                if (! $consented) {
                    CampaignMessage::create(['campaign_id' => $campaign->id, 'client_id' => $client->id, 'status' => 'skipped_no_consent']);

                    continue;
                }
                $r = mt_rand() / mt_getrandmax();
                $sentAt = $started->copy()->addSeconds(mt_rand(5, 900));
                $row = ['campaign_id' => $campaign->id, 'client_id' => $client->id, 'channel' => $consented, 'status' => 'failed', 'cost' => $consented === 'sms' ? 10 : 0,
                    'provider_message_id' => $consented === 'whatsapp' ? 'wamid.'.Str::random(24) : null];
                if ($r < $sentRate) {
                    $row['status'] = 'sent';
                    $row['sent_at'] = $sentAt;
                    $sentCount++;
                    if ($r < $deliveredRate) {
                        $row['status'] = 'delivered';
                        $row['delivered_at'] = $sentAt->copy()->addSeconds(mt_rand(2, 40));
                    }
                    if ($r < $readRate && $consented !== 'sms') {
                        $row['status'] = 'read';
                        $row['read_at'] = $sentAt->copy()->addMinutes(mt_rand(1, 300));
                    }
                    if ($r < $clickRate) {
                        $row['status'] = 'clicked';
                        $row['clicked_at'] = $sentAt->copy()->addMinutes(mt_rand(2, 400));
                    }
                } else {
                    $row['error'] = 'Recipient phone number not on WhatsApp';
                }
                $message = CampaignMessage::create($row);
                if ($r < $bookRate) {
                    $total = [15000, 28000, 42000, 65000, 95000, 120000][mt_rand(0, 5)];
                    $start = $sentAt->copy()->addDays(mt_rand(2, 10))->setTime(mt_rand(10, 18), 0);
                    $booking = Booking::create([
                        'branch_id' => $branches->random()->id, 'client_id' => $client->id, 'status' => $start->isPast() ? BookingStatus::Completed : BookingStatus::Confirmed,
                        'source' => 'website', 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(90), 'subtotal' => $total, 'total' => $total,
                        'campaign_id' => $campaign->id, 'utm_source' => 'campaign', 'completed_at' => $start->isPast() ? $start->copy()->addMinutes(90) : null,
                    ]);
                    $booking->forceFill(['created_at' => $message->clicked_at ?? $sentAt->copy()->addHours(3)])->saveQuietly();
                    $message->update(['booking_id' => $booking->id, 'booked_at' => $booking->created_at, 'revenue' => $total]);
                }
            }
            $campaign->update(['recipients_count' => $audience->count(), 'estimated_cost' => $sentCount * 10]);
        }
        Campaign::create(['name' => 'Eid al-Adha gifting', 'segment_id' => $vip->id, 'channels' => ['whatsapp', 'sms'], 'status' => 'draft', 'whatsapp_template_id' => $tplEn->id,
            'content' => ['en' => ['body' => 'Hi {first_name}, our Eid gift sets are here ✦ {link}'], 'ar' => ['body' => 'مرحباً {first_name}، وصلت هدايا العيد ✦ {link}']]]);

        // ── Automations (prebuilt recipes, active by default) ──────────────
        app(InstallAutomationRecipes::class)->handle();

        // ── Referrals ──────────────────────────────────────────────────────
        $pairs = $clients->shuffle()->take(12)->chunk(2);
        foreach ($pairs as $i => $pair) {
            [$referrer, $friend] = [$pair->first(), $pair->last()];
            if ($referrer->id === $friend->id) {
                continue;
            }
            $rewarded = $i % 3 !== 0;
            Referral::create(['referrer_client_id' => $referrer->id, 'referred_client_id' => $friend->id, 'status' => $rewarded ? 'rewarded' : 'pending',
                'points_awarded' => $rewarded ? 500 : 0, 'rewarded_at' => $rewarded ? now()->subDays(5 + $i * 4) : null]);
            $friend->forceFill(['referred_by_client_id' => $referrer->id])->saveQuietly();
        }
        Tag::firstOrCreate(['name' => 'Win-back']);

        // ── Reviews (~40) ──────────────────────────────────────────────────
        $good = [
            ['Layla is an artist — my balayage has never looked this natural.', 'ليلى فنانة حقيقية — لم يبدُ البالياج طبيعياً بهذا الشكل من قبل.'],
            ['The most relaxing hot stone massage in Doha. Pure calm.', 'أكثر مساج بالأحجار الساخنة استرخاءً في الدوحة. هدوء تام.'],
            ['Flawless gel manicure that lasted three weeks. Booking again!', 'مانيكير جل مثالي دام ثلاثة أسابيع. سأحجز مجدداً!'],
            ['Bridal makeup was breathtaking — every guest asked who did it.', 'مكياج العروس كان مذهلاً — سألتني كل الضيفات عن الخبيرة.'],
            ['Beautiful space, warm team and the best blow-dry I have had.', 'مكان جميل وفريق دافئ وأفضل استشوار حصلت عليه.'],
            ['HydraFacial left my skin glowing for days. Elena is wonderful.', 'تركت جلسة الهيدرافيشيال بشرتي مشرقة لأيام. إيلينا رائعة.'],
            ['On time, spotless and so attentive. Quiet luxury indeed.', 'في الموعد، نظيف جداً واهتمام بالتفاصيل. فخامة هادئة فعلاً.'],
            ['Lash lift is perfect — natural and elegant.', 'رفع الرموش مثالي — طبيعي وأنيق.'],
        ];
        $average = [['Lovely result, but I waited 15 minutes past my time.', 'نتيجة جميلة لكنني انتظرت 15 دقيقة بعد موعدي.']];
        $bad = [['My colour came out darker than we agreed. Disappointed.', 'جاءت الصبغة أغمق مما اتفقنا. شعرت بخيبة أمل.'], ['Pedicure felt rushed this time.', 'شعرت بأن جلسة الباديكير كانت مستعجلة هذه المرة.']];
        for ($i = 0; $i < 40; $i++) {
            $rating = $i % 13 === 5 ? 2 : ($i % 9 === 4 ? 3 : ($i % 4 === 0 ? 4 : 5));
            $pool = $rating >= 4 ? $good : ($rating === 3 ? $average : $bad);
            $text = $pool[$i % count($pool)];
            $client = $clients[$i * 4 % $clients->count()];
            $member = $staff->isNotEmpty() ? $staff[$i % $staff->count()] : null;
            Review::create([
                'client_id' => $client->id, 'branch_id' => $branches[$i % $branches->count()]->id, 'staff_id' => $member?->id,
                'rating' => $rating, 'comment' => $client->locale === 'ar' ? $text[1] : $text[0],
                'status' => $rating >= Review::PUBLISH_THRESHOLD ? 'published' : 'private', 'source' => $i % 5 === 0 ? 'whatsapp' : 'website',
                'reply' => $rating <= 3 && $i % 2 ? 'Thank you for telling us — our manager will call you personally to make it right.' : null,
                'replied_at' => $rating <= 3 && $i % 2 ? now()->subDays(40 - $i) : null,
                'created_at' => now()->subDays(60 - $i)->setTime(mt_rand(10, 21), mt_rand(0, 59)),
            ]);
        }
    }
}
