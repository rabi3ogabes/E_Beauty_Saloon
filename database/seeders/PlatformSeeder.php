<?php

namespace Database\Seeders;

use App\Domain\Billing\Models\BillingCycle;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\Module;
use App\Domain\Billing\Models\ModulePrice;
use App\Domain\Billing\Models\Plan;
use App\Models\User;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Platform catalog: modules, plans, billing cycles, module prices, platform roles and the super admin. */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            'booking' => ['Online booking', 'الحجز الإلكتروني', 'Real-time availability, deposits, reminders and a calendar your team loves.', 'توفر لحظي وعربون وتذكيرات وتقويم يحبه فريقك.', 9900],
            'pos' => ['Point of sale', 'نقطة البيع', 'Lightning-fast checkout, split payments, shifts and commissions.', 'دفع سريع ومدفوعات مجزأة وورديات وعمولات.', 9900],
            'shop' => ['Online shop', 'المتجر الإلكتروني', 'Sell your retail range with delivery, pickup and cash on delivery.', 'بيعي منتجاتك مع التوصيل والاستلام والدفع عند الاستلام.', 14900],
            'marketing' => ['Marketing & CRM', 'التسويق وإدارة العملاء', 'WhatsApp, SMS and email campaigns, automations and segments.', 'حملات واتساب ورسائل وبريد وأتمتة وشرائح عملاء.', 14900],
            'ai_studio' => ['AI Beauty Studio', 'استوديو الجمال الذكي', 'Clients preview hair, lashes, lips and nails — then book the look.', 'تجرب العميلة الشعر والرموش والشفاه والأظافر ثم تحجز الإطلالة.', 19900],
            'website' => ['Luxury website', 'موقع فاخر', 'A beautiful bilingual website on your own domain.', 'موقع ثنائي اللغة أنيق على نطاقك الخاص.', 4900],
            'loyalty' => ['Loyalty & referrals', 'الولاء والإحالات', 'Points, tiers, referrals and reviews that bring clients back.', 'نقاط ومستويات وإحالات وتقييمات تعيد العملاء.', 4900],
        ];

        $sort = 0;
        foreach ($modules as $key => [$en, $ar, $descEn, $descAr, $price]) {
            $module = Module::updateOrCreate(['key' => $key], [
                'name' => ['en' => $en, 'ar' => $ar],
                'description' => ['en' => $descEn, 'ar' => $descAr],
                'icon' => config("salon.modules.$key.icon"),
                'pricing_mode' => config("salon.modules.$key.pricing_mode"),
                'sort' => $sort++,
                'is_active' => true,
            ]);
            ModulePrice::updateOrCreate(['module_id' => $module->id, 'plan_id' => null], [
                'price_per_month' => $price,
                'per_branch' => $module->pricing_mode === 'per_branch',
            ]);
        }

        foreach ([1 => 0, 3 => 5, 6 => 10, 12 => 20] as $months => $discount) {
            BillingCycle::updateOrCreate(['plan_id' => null, 'months' => $months], ['discount_percent' => $discount]);
        }

        $plans = [
            ['essential', 'Essential', 'أساسي', 'For a single, beautifully-run salon.', 'لصالون واحد يُدار بأناقة.', 1, 6, ['website', 'booking'], 29900, 14900, 0, false],
            ['signature', 'Signature', 'سيغنتشر', 'Everything a growing salon needs.', 'كل ما يحتاجه صالون في نمو.', 2, 15, ['website', 'booking', 'pos', 'loyalty'], 59900, 19900, 200, true],
            ['maison', 'Maison', 'ميزون', 'For multi-branch beauty houses.', 'لدور التجميل متعددة الفروع.', 5, 60, ['website', 'booking', 'pos', 'loyalty', 'shop', 'marketing', 'ai_studio'], 129900, 24900, 1000, false],
        ];
        foreach ($plans as $i => [$slug, $en, $ar, $tagEn, $tagAr, $branches, $staff, $mods, $price, $extra, $credits, $featured]) {
            Plan::updateOrCreate(['slug' => $slug], [
                'name' => ['en' => $en, 'ar' => $ar],
                'tagline' => ['en' => $tagEn, 'ar' => $tagAr],
                'included_branches' => $branches,
                'included_staff' => $staff,
                'included_modules' => $mods,
                'monthly_price' => $price,
                'extra_branch_price' => $extra,
                'included_ai_credits' => $credits,
                'trial_days' => 14,
                'is_public' => true,
                'is_featured' => $featured,
                'sort' => $i,
            ]);
        }

        Coupon::updateOrCreate(['code' => 'LAUNCH20'], ['type' => 'percent', 'value' => 2000, 'applies_to' => 'first_cycle', 'max_uses' => 500, 'is_active' => true]);

        // Platform roles (team 0 = platform)
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        PermissionCatalog::ensurePermissions();
        foreach (PermissionCatalog::platformRoleDefaults() as $role => $perms) {
            $r = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web', 'tenant_id' => 0]);
            $r->syncPermissions($perms === ['*'] ? Permission::where('name', 'like', 'platform.%')->pluck('name') : $perms);
        }

        $admin = User::where('email', 'admin@luxesalon.test')->first() ?? new User;
        $admin->forceFill([
            'name' => 'Platform Owner',
            'email' => 'admin@luxesalon.test',
            'password' => 'password',
            'is_platform' => true,
            'email_verified_at' => now(),
        ])->save();
        $admin->syncRoles(['platform_owner']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
