<?php

namespace Database\Seeders;

use App\Domain\Billing\Actions\MarkInvoicePaid;
use App\Domain\Billing\Models\Plan;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Models\Tag;
use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Domain\Team\Models\Staff;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo salon "E Beauty Saloon" (lumiere.{base_domain}):
 * 2 branches, 8 staff, 30 services, 40 products, 200 clients. Owner: owner@lumiere.test / password
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $existing = Tenant::where('slug', 'lumiere')->first();
        if ($existing && $existing->branches()->exists()) {
            return;
        }
        if ($existing) { // a previous run stopped half-way: finish seeding it
            app(TenantContext::class)->run($existing, fn () => $this->seedTenant($existing));

            return;
        }

        $plan = Plan::where('slug', 'maison')->firstOrFail();
        $tenant = app(CreateTenant::class)->handle([
            'name' => 'E Beauty Saloon',
            'slug' => 'lumiere',
            'email' => 'hello@lumiere.test',
            'phone' => '+97444001122',
            'owner_name' => 'Noor Al-Thani',
            'owner_email' => 'owner@lumiere.test',
            'owner_phone' => '+97455001122',
            'owner_password' => 'password',
        ], ['plan_id' => $plan->id, 'cycle_months' => 12, 'branches' => 2, 'modules' => []]);

        app(MarkInvoicePaid::class)->handle($tenant->invoices()->first(), 'manual', 'DEMO-SEED');

        $tenant->update([
            'onboarded_at' => now(),
            'legal_name' => 'E Beauty Saloon W.L.L.',
            'branding' => array_merge(Tenant::DEFAULT_BRANDING, [
                'about' => 'A sanctuary of considered beauty in Doha — couture hair, flawless nails and skin rituals by an award-winning team.',
                'social' => ['instagram' => 'https://instagram.com/', 'tiktok' => 'https://tiktok.com/'],
                'receipt_footer' => 'Thank you for choosing E Beauty Saloon ✦',
            ]),
        ]);

        app(TenantContext::class)->run($tenant->fresh(), fn () => $this->seedTenant($tenant));
    }

    protected function seedTenant(Tenant $tenant): void
    {
        $faker = fake();
        $hours = Branch::defaultHours();
        $hours['fri'] = ['open' => '14:00', 'close' => '23:00', 'closed' => false, 'breaks' => []];

        $westBay = Branch::create([
            'name' => ['en' => 'West Bay', 'ar' => 'الخليج الغربي'], 'slug' => 'west-bay',
            'address' => ['en' => 'Tower 3, Diplomatic Area, West Bay, Doha', 'ar' => 'البرج 3، المنطقة الدبلوماسية، الخليج الغربي، الدوحة'],
            'area' => 'West Bay', 'city' => 'Doha', 'lat' => 25.3235, 'lng' => 51.5310, 'phone' => '+97444001122', 'whatsapp' => '+97455001122',
            'opening_hours' => $hours, 'sort' => 0,
        ]);
        $pearl = Branch::create([
            'name' => ['en' => 'The Pearl', 'ar' => 'اللؤلؤة'], 'slug' => 'the-pearl',
            'address' => ['en' => 'Porto Arabia, Tower 21, The Pearl-Qatar', 'ar' => 'بورتو أرابيا، برج 21، اللؤلؤة قطر'],
            'area' => 'The Pearl', 'city' => 'Doha', 'lat' => 25.3700, 'lng' => 51.5510, 'phone' => '+97444003344', 'whatsapp' => '+97455003344',
            'opening_hours' => $hours, 'sort' => 1,
        ]);
        $branches = collect([$westBay, $pearl]);
        foreach ($branches as $b) {
            foreach ([['Chair 1', 'chair'], ['Chair 2', 'chair'], ['Chair 3', 'chair'], ['Wash basin', 'wash_basin'], ['Pedicure station', 'pedicure_station'], ['Treatment room', 'room']] as [$n, $type]) {
                $b->resources()->create(['name' => $n, 'type' => $type, 'tenant_id' => $tenant->id]);
            }
        }

        // Catalog — 6 categories, 30 services
        $catalog = [
            ['hair', 'Hair', 'الشعر', [
                ['Signature blow-dry', 'استشوار سيغنتشر', 45, 15000, ['chair', 'wash_basin'], 21],
                ['Precision cut & style', 'قص وتصفيف دقيق', 60, 28000, ['chair'], 42],
                ['Root colour', 'صبغة الجذور', 90, 42000, ['chair'], 42],
                ['Balayage', 'بالياج', 180, 120000, ['chair'], 90],
                ['Full highlights', 'هايلايت كامل', 150, 95000, ['chair'], 70],
                ['Keratin smoothing', 'كيراتين', 150, 110000, ['chair'], 120],
                ['Olaplex treatment', 'علاج أولابلكس', 45, 25000, ['chair', 'wash_basin'], 30],
                ['Bridal hair', 'تسريحة عروس', 120, 150000, ['chair'], null],
            ]],
            ['nails', 'Nails', 'الأظافر', [
                ['Gel manicure', 'مانيكير جل', 60, 18000, [], 21],
                ['Classic manicure', 'مانيكير كلاسيك', 45, 12000, [], 14],
                ['Gel pedicure', 'باديكير جل', 75, 22000, ['pedicure_station'], 28],
                ['Spa pedicure', 'باديكير سبا', 60, 20000, ['pedicure_station'], 28],
                ['Nail art (per set)', 'رسم أظافر', 30, 8000, [], null],
                ['Acrylic extensions', 'تركيب أكريليك', 120, 35000, [], 21],
            ]],
            ['lashes', 'Lashes & brows', 'الرموش والحواجب', [
                ['Classic lash extensions', 'رموش كلاسيك', 120, 45000, ['room'], 21],
                ['Volume lash extensions', 'رموش فوليوم', 150, 60000, ['room'], 21],
                ['Lash lift & tint', 'رفع وصبغ الرموش', 60, 25000, ['room'], 42],
                ['Brow lamination', 'لاميناشن الحواجب', 45, 22000, ['room'], 42],
                ['Brow threading', 'تشقير الحواجب بالخيط', 15, 6000, [], 21],
            ]],
            ['makeup', 'Makeup', 'المكياج', [
                ['Soirée makeup', 'مكياج سهرة', 75, 45000, ['room'], null],
                ['Bridal makeup', 'مكياج عروس', 150, 250000, ['room'], null],
                ['Makeup lesson', 'درس مكياج', 90, 50000, ['room'], null],
            ]],
            ['skin', 'Skin', 'البشرة', [
                ['HydraFacial', 'هيدرافيشيال', 60, 65000, ['room'], 30],
                ['Gold radiance facial', 'فيشيال الذهب', 75, 75000, ['room'], 30],
                ['Deep cleansing facial', 'تنظيف بشرة عميق', 60, 40000, ['room'], 30],
            ]],
            ['spa', 'Spa', 'السبا', [
                ['Moroccan bath', 'حمام مغربي', 90, 45000, ['room'], 30],
                ['Hot stone massage', 'مساج بالأحجار الساخنة', 60, 50000, ['room'], 30],
                ['Aromatherapy massage', 'مساج بالزيوت العطرية', 60, 42000, ['room'], 30],
                ['Body scrub & wrap', 'تقشير ولف الجسم', 75, 48000, ['room'], 45],
                ['Head spa ritual', 'طقوس سبا الرأس', 45, 30000, ['wash_basin'], 30],
            ]],
        ];

        $services = collect();
        foreach ($catalog as $ci => [$slug, $en, $ar, $items]) {
            $cat = ServiceCategory::create(['name' => ['en' => $en, 'ar' => $ar], 'slug' => $slug, 'sort' => $ci]);
            foreach ($items as $si => [$sen, $sar, $duration, $price, $res, $cycle]) {
                $service = Service::create([
                    'service_category_id' => $cat->id,
                    'name' => ['en' => $sen, 'ar' => $sar],
                    'slug' => Str::slug($sen),
                    'description' => ['en' => "Our {$sen} ritual, tailored to you by a senior artist.", 'ar' => "طقوس {$sar} مصممة خصيصاً لكِ على يد خبيرة."],
                    'duration_minutes' => $duration,
                    'buffer_after' => $duration >= 90 ? 15 : 5,
                    'price' => $price,
                    'gender' => 'women',
                    'deposit_type' => $price >= 90000 ? 'percent' : null,
                    'deposit_value' => $price >= 90000 ? 3000 : 0, // 30% in basis points
                    'resource_types' => $res,
                    'rebook_after_days' => $cycle,
                    'sort' => $si,
                ]);
                $service->branches()->attach($westBay->id, ['is_active' => true]);
                // The Pearl: premium location — 10% higher price on hair, longer on balayage
                $service->branches()->attach($pearl->id, [
                    'is_active' => true,
                    'price' => $slug === 'hair' ? (int) round($price * 1.1) : null,
                    'duration_minutes' => $sen === 'Balayage' ? 200 : null,
                ]);
                $services->push($service);
            }
        }
        $longHair = $services->firstWhere('slug', 'signature-blow-dry');
        foreach ([['Short', 'قصير', 45, 15000], ['Medium', 'متوسط', 60, 18000], ['Long', 'طويل', 75, 22000]] as $i => [$en, $ar, $d, $p]) {
            $longHair->variants()->create(['tenant_id' => $tenant->id, 'name' => ['en' => $en, 'ar' => $ar], 'duration_minutes' => $d, 'price' => $p, 'sort' => $i]);
        }

        // Team — 8 staff
        $team = [
            ['Layla Haddad', 'ليلى حداد', 'Creative Director', 'المديرة الإبداعية', ['hair', 'makeup'], [$westBay], '#C8A96A'],
            ['Sofia Moreau', 'صوفيا مورو', 'Senior Colourist', 'خبيرة صبغات', ['hair'], [$westBay, $pearl], '#B07D62'],
            ['Mariam Khalil', 'مريم خليل', 'Nail Artist', 'فنانة أظافر', ['nails'], [$westBay], '#D9A5B3'],
            ['Grace Santos', 'جريس سانتوس', 'Nail Technician', 'أخصائية أظافر', ['nails'], [$pearl], '#E3B5A4'],
            ['Hana Yousef', 'هناء يوسف', 'Lash & Brow Artist', 'خبيرة رموش وحواجب', ['lashes'], [$westBay, $pearl], '#8E7DBE'],
            ['Amira Nasser', 'أميرة ناصر', 'Makeup Artist', 'خبيرة مكياج', ['makeup', 'lashes'], [$pearl], '#A4473F'],
            ['Elena Petrova', 'إيلينا بيتروفا', 'Skin Therapist', 'أخصائية بشرة', ['skin', 'spa'], [$westBay], '#4F7A5A'],
            ['Joy Reyes', 'جوي رييس', 'Spa Therapist', 'أخصائية سبا', ['spa', 'skin'], [$pearl], '#6B7FA3'],
        ];
        $categories = ServiceCategory::all()->keyBy('slug');
        foreach ($team as $i => [$en, $ar, $tEn, $tAr, $cats, $staffBranches, $color]) {
            $staff = Staff::create([
                'name' => ['en' => $en, 'ar' => $ar], 'title' => ['en' => $tEn, 'ar' => $tAr],
                'bio' => ['en' => "$en brings years of luxury salon experience.", 'ar' => "تتمتع $ar بخبرة سنوات في الصالونات الفاخرة."],
                'color' => $color, 'rating' => $faker->randomFloat(2, 4.6, 5), 'ratings_count' => $faker->numberBetween(20, 240), 'sort' => $i,
            ]);
            $staff->branches()->attach(collect($staffBranches)->pluck('id'));
            $catIds = collect($cats)->map(fn ($c) => $categories[$c]->id);
            $staff->services()->attach($services->whereIn('service_category_id', $catIds)->pluck('id')->mapWithKeys(fn ($id) => [$id => [
                'price' => $i === 0 ? null : null,
            ]])->all());
            foreach ($staffBranches as $bi => $branch) {
                foreach (range(0, 6) as $weekday) {
                    // Split-branch staff alternate days; everyone has one day off.
                    if ($weekday === ($i % 7)) {
                        continue;
                    }
                    if (count($staffBranches) > 1 && ($weekday % 2) !== $bi) {
                        continue;
                    }
                    $isFriday = $weekday === 5;
                    $staff->schedules()->create([
                        'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'weekday' => $weekday,
                        'starts_at' => $isFriday ? '14:00' : '10:00', 'ends_at' => $isFriday ? '23:00' : '20:00',
                        'breaks' => [['14:00', '15:00']],
                    ]);
                }
            }
        }
        // Senior pricing for the creative director
        $layla = Staff::where('color', '#C8A96A')->first();
        $layla->services()->updateExistingPivot($services->firstWhere('slug', 'precision-cut-style')->id, ['price' => 38000]);

        // Team logins — each with its own POS PIN (a PIN identifies its holder at the till, so no two share one)
        $mk = function (string $name, string $email, string $role, array $branchIds, string $pin) use ($tenant) {
            $u = new User(['name' => $name, 'email' => $email, 'password' => 'password']);
            $u->tenant_id = $tenant->id;
            $u->save();
            $u->assignRole($role);
            $u->branches()->sync($branchIds);
            $u->setPosPin($pin);

            return $u;
        };
        $mk('Rania Saeed', 'manager@lumiere.test', 'branch_manager', [$westBay->id], '1234');
        $mk('Fatima Ali', 'reception@lumiere.test', 'receptionist', [$westBay->id], '5678');
        $stylistUser = $mk('Sofia Moreau', 'stylist@lumiere.test', 'stylist', [$westBay->id, $pearl->id], '2468');
        Staff::where('color', '#B07D62')->update(['user_id' => $stylistUser->id]);
        $tenant->owner->setPosPin('0000');

        // Products — 40 with variants & stock (through the ledger)
        $brands = collect(['Kérastase', 'Olaplex', 'Oribe', 'OPI', 'Dior Beauty', 'La Mer', 'Moroccanoil', 'Augustinus Bader'])
            ->map(fn ($b) => Brand::create(['name' => $b, 'slug' => Str::slug($b)]));
        $pcats = collect([['hair-care', 'Hair care', 'العناية بالشعر'], ['styling', 'Styling', 'التصفيف'], ['nails', 'Nails', 'الأظافر'], ['skin', 'Skin care', 'العناية بالبشرة'], ['makeup', 'Makeup', 'المكياج']])
            ->map(fn ($c, $i) => ProductCategory::create(['slug' => $c[0], 'name' => ['en' => $c[1], 'ar' => $c[2]], 'sort' => $i]));
        $productNames = [
            'Elixir Ultime Oil', 'Bain Satin Shampoo', 'Fondant Fluidealiste', 'No.3 Hair Perfector', 'No.4 Bond Shampoo', 'No.5 Bond Conditioner', 'No.7 Bonding Oil',
            'Gold Lust Shampoo', 'Supershine Cream', 'Dry Texturizing Spray', 'Apres Beach Wave Spray', 'Nail Envy Strengthener', 'Infinite Shine Top Coat', 'Nail Lacquer',
            'Cuticle Oil to Go', 'Pro Spa Hand Cream', 'Crème de la Mer', 'The Concentrate', 'The Eye Concentrate', 'Soft Fluid Foundation', 'Lip Glow Oil', 'Rouge Lipstick',
            'Forever Skin Glow', 'Backstage Face & Body', 'Treatment Original', 'Intense Curl Cream', 'Hydrating Mask', 'Dry Shampoo Light', 'The Cream', 'The Rich Cream',
            'Face Oil', 'Body Lotion', 'Blowout Spray', 'Heat Protect Primer', 'Gloss Serum', 'Scalp Detox', 'Volume Mousse', 'Shine Mist', 'Repair Mask', 'Silk Pillowcase',
        ];
        foreach ($productNames as $i => $name) {
            $hasShades = in_array($name, ['Nail Lacquer', 'Rouge Lipstick', 'Soft Fluid Foundation', 'Lip Glow Oil'], true);
            $product = Product::create([
                'product_category_id' => $pcats[$i % 5]->id,
                'brand_id' => $brands[$i % 8]->id,
                'name' => ['en' => $name, 'ar' => $name],
                'slug' => Str::slug($name),
                'description' => ['en' => "Professional-grade {$name}, chosen by our artists.", 'ar' => "{$name} احترافي اختارته خبيراتنا."],
                'usage' => $i % 9 === 0 ? 'both' : 'retail',
                'attributes' => ['hair_type' => $faker->randomElement(['all', 'curly', 'fine', 'thick']), 'skin_type' => $faker->randomElement(['all', 'dry', 'oily', 'sensitive'])],
            ]);
            $price = $faker->numberBetween(9, 120) * 1000 + 500;
            $variants = $hasShades
                ? [['Rose Nude', '#D8A49A'], ['Champagne', '#E8CFA8'], ['Rouge Noir', '#5B1A26'], ['Coral Bloom', '#E9765B']]
                : [[$faker->randomElement(['50ml', '100ml', '250ml']), null]];
            foreach ($variants as $vi => [$vname, $swatch]) {
                $variant = $product->variants()->create([
                    'name' => ['en' => $vname, 'ar' => $vname],
                    'sku' => 'LUM-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT).'-'.($vi + 1),
                    'barcode' => '62'.str_pad((string) (($i + 1) * 10 + $vi), 11, '0', STR_PAD_LEFT),
                    'swatch' => $swatch, 'cost' => (int) ($price * 0.45), 'price' => $price,
                    'sale_price' => $i % 7 === 0 ? (int) ($price * 0.85) : null, 'is_default' => $vi === 0,
                ]);
                foreach ($branches as $b) {
                    app(StockLedger::class)->move($b->id, $variant, $faker->numberBetween(2, 24), 'purchase', null, "seed:{$variant->id}:{$b->id}", 'Opening stock');
                }
            }
        }

        // Clients — 200
        $tags = collect(['VIP', 'Bride 2026', 'Colour client', 'Corporate', 'Sensitive skin'])->map(fn ($t) => Tag::create(['name' => $t]));
        $first = ['Noura', 'Aisha', 'Fatima', 'Maryam', 'Hessa', 'Sara', 'Lulwa', 'Dana', 'Reem', 'Jawaher', 'Emily', 'Olivia', 'Chloe', 'Priya', 'Anna', 'Lina', 'Yasmin', 'Haya', 'Shaikha', 'Mona'];
        $last = ['Al-Kuwari', 'Al-Sulaiti', 'Al-Marri', 'Al-Mohannadi', 'Al-Naimi', 'Haddad', 'Mansour', 'Khoury', 'Smith', 'Johnson', 'Sharma', 'Rossi', 'Al-Emadi', 'Al-Baker', 'Fakhroo'];
        for ($i = 0; $i < 200; $i++) {
            $client = Client::create([
                'name' => $faker->randomElement($first).' '.$faker->randomElement($last),
                'phone' => '+974'.$faker->unique()->numerify('5#######'),
                'email' => $i % 3 === 0 ? null : 'client'.$i.'@example.com',
                'gender' => 'female',
                'birthday' => $faker->dateTimeBetween('-55 years', '-19 years'),
                'locale' => $i % 2 ? 'ar' : 'en',
                'preferred_branch_id' => $branches->random()->id,
                'source' => $faker->randomElement(['walk_in', 'website', 'instagram', 'referral', 'whatsapp']),
                'lifetime_value' => $faker->numberBetween(0, 1500) * 1000,
                'visits_count' => $faker->numberBetween(0, 40),
                'last_visit_at' => $faker->optional(0.85)->dateTimeBetween('-120 days', '-1 day'),
                'is_vip' => $i % 17 === 0,
                'allergies' => $i % 23 === 0 ? 'PPD sensitivity — patch test required' : null,
            ]);
            foreach (Client::CHANNELS as $ch) {
                $client->setConsent($ch, $faker->boolean(80), 'import');
            }
            if ($i % 6 === 0) {
                $client->tags()->attach($tags->random()->id);
            }
        }

        // Salon payment methods
        foreach ([['cash', ['pos', 'shop']], ['card_terminal', ['pos']], ['cod', ['shop']]] as $s => [$driver, $for]) {
            TenantPaymentGateway::create(['driver' => $driver, 'label' => __('salon.payments.drivers.'.$driver), 'enabled_for' => $for, 'is_active' => true, 'mode' => 'live', 'sort' => $s]);
        }
        TenantPaymentGateway::create([
            'driver' => 'sadad', 'label' => 'SADAD', 'mode' => 'test', 'is_active' => true, 'sort' => 10,
            'enabled_for' => ['booking', 'shop', 'pos', 'deposits'],
            'credentials' => ['merchant_id' => 'demo-merchant', 'secret_key' => 'demo-secret', 'website' => 'lumiere.test'],
        ]);
    }
}
