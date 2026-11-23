<?php

namespace Database\Seeders\Demo;

use App\Domain\AIStudio\Actions\CreditLedger;
use App\Domain\AIStudio\Actions\GrantMonthlyCredits;
use App\Domain\AIStudio\Models\AICreditPack;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\AIProvider;
use App\Domain\AIStudio\Models\AITaskPrice;
use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\AIStudio\Models\ClientLook;
use App\Domain\AIStudio\Models\Style;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/** AI Studio demo: platform providers/prices/packs + E Beauty Saloon's style library, wallet and past jobs. */
class AIStudioDemoSeeder extends Seeder
{
    public static int $order = 70;

    public function run(): void
    {
        AITaskPrice::seedDefaults();

        $providers = [
            ['gemini', 'Google Gemini (Nano Banana)', 1, ['image', 'text'], ['image' => 4, 'text' => 1]],
            ['openai', 'OpenAI Images', 2, ['image', 'text'], ['image' => 6, 'text' => 2]],
            ['higgsfield', 'Higgsfield', 3, ['image', 'video'], ['image' => 5]],
            ['anthropic', 'Anthropic Claude', 1, ['text'], ['text' => 2]],
        ];
        foreach ($providers as [$driver, $name, $priority, $caps, $costs]) {
            AIProvider::query()->firstOrCreate(['driver' => $driver, 'tenant_id' => null], [
                'name' => $name, 'priority' => $priority, 'capabilities' => $caps, 'unit_costs' => $costs, 'timeout_seconds' => 60, 'enabled' => true,
            ]);
        }

        foreach ([['Starter', 'المبتدئ', 500, 49900, false], ['Studio', 'الاستوديو', 2000, 179900, true], ['Maison', 'ميزون', 10000, 799900, false]] as $i => [$en, $ar, $credits, $price, $featured]) {
            AICreditPack::query()->firstOrCreate(['credits' => $credits], ['name' => ['en' => $en, 'ar' => $ar], 'price' => $price, 'currency' => 'QAR', 'is_featured' => $featured, 'sort' => $i]);
        }

        $tenant = Tenant::where('slug', 'lumiere')->first();
        if (! $tenant) {
            return;
        }
        app(TenantContext::class)->run($tenant, fn () => $this->seedTenant($tenant));
    }

    protected function seedTenant(Tenant $tenant): void
    {
        if (Style::exists()) {
            return;
        }
        $settings = $tenant->settings ?? [];
        $settings['ai'] = array_merge($settings['ai'] ?? [], ['free_tries_per_client' => 3, 'price_per_look' => 1000, 'retention_days' => 7, 'kiosk_enabled' => true]);
        $tenant->update(['settings' => $settings]);

        $services = Service::all()->keyBy('slug');
        $shade = fn (string $name) => ProductVariant::query()->get()->filter(fn ($v) => $v->getTranslation('name', 'en') === $name)->pluck('id')->take(2)->values()->all();

        // [category, option, en, ar, prompt, service slug, swatch, shades]
        $styles = [
            ['hair', 'balayage', 'Honey balayage', 'بالياج عسلي', 'Soft honey-blonde balayage melting from natural roots to sun-kissed mid-lengths and ends, glossy finish.', 'balayage', '#C9A06A', []],
            ['hair', 'balayage', 'Caramel ribbons', 'خصل الكراميل', 'Rich caramel balayage ribbons on dark brunette hair, seamless blend, luminous shine.', 'balayage', '#A86B3C', []],
            ['hair', 'highlights', 'Champagne highlights', 'هايلايت شمبانيا', 'Fine champagne-blonde foil highlights throughout, bright face-framing pieces.', 'full-highlights', '#E8D4A8', []],
            ['hair', 'color', 'Espresso gloss', 'لمعة الإسبريسو', 'Deep espresso brown all-over colour with a mirror-like gloss.', 'root-colour', '#3B2418', []],
            ['hair', 'color', 'Copper glow', 'توهج النحاس', 'Warm copper-auburn hair colour, rich and multi-tonal.', 'root-colour', '#B5562E', []],
            ['hair', 'cut', 'Parisian bob', 'بوب باريسي', 'Chin-length French bob with soft curtain bangs, effortless texture.', 'precision-cut-style', null, []],
            ['hair', 'length', 'Long layers', 'طبقات طويلة', 'Long flowing layered cut with face-framing layers below the shoulders.', 'precision-cut-style', null, []],
            ['hair', 'curls', 'Hollywood waves', 'تموجات هوليوود', 'Glamorous polished Hollywood waves, deep side part, high shine.', 'signature-blow-dry', null, []],
            ['hair', 'curls', 'Soft beach waves', 'تموجات الشاطئ', 'Relaxed soft beach waves with natural movement.', 'signature-blow-dry', null, []],
            ['eyes', 'lashes', 'Classic lashes', 'رموش كلاسيك', 'Natural classic eyelash extensions, one-to-one, subtle lift.', 'classic-lash-extensions', null, []],
            ['eyes', 'lashes', 'Volume lashes', 'رموش فوليوم', 'Fluffy volume eyelash extensions, dramatic yet soft, cat-eye mapping.', 'volume-lash-extensions', null, []],
            ['eyes', 'brows', 'Laminated brows', 'حواجب لاميناشن', 'Brushed-up laminated brows, full and feathery, natural colour.', 'brow-lamination', null, []],
            ['lips', 'shade', 'Rose nude lips', 'شفاه وردية نود', 'Satin rose-nude lipstick, softly defined lip line.', 'soiree-makeup', '#D8A49A', 'Rose Nude'],
            ['lips', 'shade', 'Rouge noir lips', 'شفاه روج نوار', 'Deep rouge-noir matte lipstick, crisp edges.', 'soiree-makeup', '#5B1A26', 'Rouge Noir'],
            ['lips', 'finish', 'Glass gloss', 'لمعة زجاجية', 'Sheer glass-like lip gloss over natural lips, juicy shine.', 'soiree-makeup', '#E8CFA8', 'Champagne'],
            ['nails', 'color', 'Rouge noir gel', 'جل روج نوار', 'Glossy rouge-noir gel polish on medium almond nails.', 'gel-manicure', '#5B1A26', 'Rouge Noir'],
            ['nails', 'color', 'Milky nude', 'نود حليبي', 'Milky sheer nude gel on short squoval nails, clean-girl finish.', 'gel-manicure', '#F1DCD3', 'Rose Nude'],
            ['nails', 'art', 'Gold chrome French', 'فرنش كروم ذهبي', 'Fine gold chrome French tips on nude almond nails.', 'nail-art-per-set', '#C8A96A', []],
            ['nails', 'shape', 'Coral almond', 'لوز مرجاني', 'Long almond-shaped acrylic nails in glossy coral bloom.', 'acrylic-extensions', '#E9765B', 'Coral Bloom'],
            ['pedicure', 'color', 'Classic red toes', 'باديكير أحمر', 'Classic glossy red pedicure, neat cuticles.', 'gel-pedicure', '#A4262C', []],
            ['pedicure', 'color', 'Champagne shimmer', 'لمعة الشمبانيا', 'Champagne shimmer pedicure with a high-gloss top coat.', 'spa-pedicure', '#E8CFA8', 'Champagne'],
            ['makeup', 'soiree', 'Soirée glow', 'إطلالة سهرة', 'Evening makeup: soft bronze smoky eye, luminous skin, sculpted cheeks, nude-rose lips.', 'soiree-makeup', null, []],
            ['makeup', 'day', 'Fresh day glow', 'إشراقة نهارية', 'Fresh natural day makeup: dewy skin, brushed brows, peach blush, tinted balm.', 'makeup-lesson', null, []],
            ['bridal', 'arabic', 'Arabic bride', 'عروس عربية', 'Arabic bridal makeup: dramatic winged liner, gold-bronze eyes, flawless matte skin, deep rose lips; elegant updo.', 'bridal-makeup', null, []],
            ['bridal', 'modern', 'Modern minimal bride', 'عروس عصرية', 'Modern minimal bridal look: radiant skin, soft champagne eyes, fluttery lashes, sleek low chignon.', 'bridal-hair', null, []],
        ];

        foreach ($styles as $i => [$cat, $option, $en, $ar, $prompt, $slug, $swatch, $shadeName]) {
            Style::create([
                'category' => $cat, 'option' => $option, 'name' => ['en' => $en, 'ar' => $ar],
                'description' => ['en' => 'Curated by our artists — '.strtolower($en).'.', 'ar' => 'من اختيار خبيراتنا — '.$ar.'.'],
                'prompt' => $prompt, 'task' => Style::CATEGORIES[$cat]['task'], 'swatch' => $swatch,
                'service_id' => $services[$slug]->id ?? null,
                'product_variant_ids' => $shadeName ? $shade($shadeName) : null,
                'sort' => $i,
            ]);
        }

        // Wallet: monthly included credits + one paid pack.
        app(GrantMonthlyCredits::class)->handle($tenant->fresh());
        app(CreditLedger::class)->credit($tenant, 500, 'purchase', 'demo-pack-'.$tenant->id, 'Starter pack (demo)');
        AIWallet::for($tenant)->update(['low_balance_threshold' => 100]);

        // Past jobs (no photos kept) for usage stats and the margin report.
        $gemini = AIProvider::where('driver', 'gemini')->whereNull('tenant_id')->first();
        $openai = AIProvider::where('driver', 'openai')->whereNull('tenant_id')->first();
        $claude = AIProvider::where('driver', 'anthropic')->whereNull('tenant_id')->first();
        $clients = Client::inRandomOrder()->limit(25)->get();
        $all = Style::all();
        $ledger = app(CreditLedger::class);
        foreach (range(1, 40) as $n) {
            $style = $all->random();
            $failed = $n % 13 === 0;
            $provider = $style->category === 'makeup' || $n % 4 === 0 ? $openai : $gemini;
            $credits = (int) AITaskPrice::for($style->task)->credits;
            $at = now()->subDays(random_int(0, 28))->subMinutes(random_int(0, 600));
            $job = AIJob::create([
                'client_id' => $clients->random()->id, 'task' => $style->task, 'source' => $n % 5 === 0 ? 'kiosk' : 'studio', 'style_id' => $style->id,
                'ai_provider_id' => $failed ? null : $provider->id, 'provider_driver' => $failed ? null : $provider->driver, 'model' => $failed ? null : $provider->model('image'),
                'prompt' => $style->prompt, 'cost_credits' => $credits, 'charged_credits' => $failed ? 0 : $credits,
                'provider_cost' => $failed ? 0 : $provider->unitCost('image'), 'latency_ms' => random_int(4200, 16000),
                'status' => $failed ? 'failed' : 'succeeded', 'error' => $failed ? __('ai_studio.errors.all_providers_failed') : null, 'attempts' => 1,
                'attempt_log' => [['provider' => $provider->name, 'driver' => $provider->driver, 'ok' => ! $failed, 'ms' => 9000]],
                'started_at' => $at, 'finished_at' => $at->copy()->addSeconds(12), 'created_at' => $at, 'updated_at' => $at,
            ]);
            if (! $failed) {
                $ledger->debit($tenant, $credits, 'charge', 'job:'.$job->id.':charge', __('ai_studio.wallet.charge', ['task' => $job->task]), 'ai_job', $job->id, allowNegative: true);
                ClientLook::create(['client_id' => $job->client_id, 'ai_job_id' => $job->id, 'style_id' => $style->id, 'category' => $style->category, 'expires_at' => now()->addDays(7), 'created_at' => $at]);
            }
        }
        foreach (range(1, 6) as $n) {
            AIJob::create([
                'client_id' => $clients->random()->id, 'task' => 'consultant', 'source' => 'consultant', 'ai_provider_id' => $claude->id, 'provider_driver' => 'anthropic',
                'model' => 'claude-opus-5-5', 'cost_credits' => 1, 'charged_credits' => 1, 'provider_cost' => 4, 'latency_ms' => 5200, 'status' => 'succeeded', 'attempts' => 1,
                'output_refs' => ['analysis' => ['face_shape' => 'oval', 'skin_tone' => 'medium', 'undertone' => 'warm', 'hair_texture' => 'wavy', 'notes' => null],
                    'looks' => $all->random(3)->map(fn ($s) => ['style_id' => $s->id, 'reason' => 'Flatters warm undertones.'])->values()->all()],
            ]);
        }
    }
}
