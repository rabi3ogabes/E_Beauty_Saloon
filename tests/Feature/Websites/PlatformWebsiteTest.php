<?php

use App\Domain\Billing\Actions\CalculatePrice;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Plan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Websites\Models\BlogPost;
use App\Domain\Websites\Models\Lead;
use App\Domain\Websites\Models\PlatformPage;
use App\Domain\Websites\Support\PlatformContent;
use App\Filament\Admin\Resources\PlatformPages\Pages\CreatePlatformPage;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('renders every platform page with built-in defaults when the CMS is empty', function () {
    BlogPost::create(['slug' => 'hello', 'title' => ['en' => 'Hello salon'], 'excerpt' => ['en' => 'Intro'], 'body' => ['en' => '<p>Body text</p>'], 'published_at' => now()->subDay()]);

    $this->get(centralUrl('/'))->assertOk()->assertSee(__('platform.hero.title'))->assertSee('SoftwareApplication', false)->assertSee('hreflang="ar"', false);
    $this->get(centralUrl('pricing'))->assertOk()->assertSee('luxePricing');
    $this->get(centralUrl('features/ai_studio'))->assertOk()->assertSee('AI Beauty Studio');
    $this->get(centralUrl('features/nope'))->assertNotFound();
    $this->get(centralUrl('demo'))->assertOk();
    $this->get(centralUrl('signup'))->assertOk();
    $this->get(centralUrl('blog'))->assertOk()->assertSee('Hello salon');
    $this->get(centralUrl('blog/hello'))->assertOk()->assertSee('Body text')->assertSee('BlogPosting', false);
    $this->get(centralUrl('help'))->assertOk()->assertSee('FAQPage', false);
    $this->get(centralUrl('legal/terms'))->assertOk();
    $this->get(centralUrl('legal/privacy'))->assertOk()->assertSee('never used to train');
    $this->get(centralUrl('/?lang=ar'))->assertOk()->assertSee('dir="rtl"', false)->assertSee(__('platform.hero.title', [], 'ar'));
});

it('renders platform pages from the CMS when published', function () {
    PlatformPage::create(['slug' => 'home', 'title' => ['en' => 'Home'], 'seo_title' => ['en' => 'Luxury salon software'], 'sections' => [
        ['type' => 'hero', 'data' => ['en' => ['title' => 'A brand new headline', 'body' => 'From the CMS']]],
        ['type' => 'cta', 'data' => ['en' => ['title' => 'Join now', 'cta_label' => 'Go', 'cta_url' => '/signup']]],
    ]]);
    PlatformPage::create(['slug' => 'about', 'title' => ['en' => 'About us'], 'in_footer' => true, 'sections' => [['type' => 'text', 'data' => ['en' => ['body' => 'We love salons.']]]]]);

    $this->get(centralUrl('/'))->assertOk()->assertSee('A brand new headline')->assertSee('Luxury salon software')->assertDontSee(__('platform.hero.title'));
    $this->get(centralUrl('p/about'))->assertOk()->assertSee('We love salons.');
});

it('pricing quote endpoint matches CalculatePrice', function () {
    $plan = Plan::where('slug', 'signature')->first();
    $expected = app(CalculatePrice::class)->handle($plan, 6, 4, ['shop', 'ai_studio']);

    $this->getJson(centralUrl('pricing/quote?plan=signature&cycle=6&branches=4&modules[]=shop&modules[]=ai_studio'))
        ->assertOk()
        ->assertJsonPath('total', $expected->total)
        ->assertJsonPath('subtotal', $expected->subtotal)
        ->assertJsonPath('cycle_discount', $expected->cycleDiscount)
        ->assertJsonPath('monthly_equivalent', $expected->monthlyEquivalent())
        ->assertJsonPath('formatted.total', money($expected->total, $expected->currency))
        ->assertJsonCount(count($expected->lines), 'lines');

    $this->getJson(centralUrl('pricing/quote?plan=signature&cycle=5&branches=1'))->assertUnprocessable();
    $this->getJson(centralUrl('pricing/quote?plan=nope&cycle=1&branches=1'))->assertUnprocessable();
});

it('signup creates a trial tenant and redirects to the tenant domain', function () {
    $this->post(centralUrl('signup'), [
        'salon_name' => 'Velvet Room', 'slug' => 'velvet', 'owner_name' => 'Dana', 'email' => 'dana@velvet.test', 'phone' => '+97455551212',
        'password' => 'very-secret-pass', 'plan' => 'signature', 'branches' => 2, 'terms' => '1',
    ])->assertRedirect('http://velvet.'.config('salon.base_domain').'/dashboard/login?email=dana%40velvet.test');

    $tenant = Tenant::where('slug', 'velvet')->firstOrFail();
    expect($tenant->status)->toBe(TenantStatus::Trial)
        ->and($tenant->trial_ends_at)->not->toBeNull()
        ->and($tenant->currentSubscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($tenant->currentSubscription->plan->slug)->toBe('signature')
        ->and($tenant->hasModule('booking'))->toBeTrue()
        ->and($tenant->owner->email)->toBe('dana@velvet.test');

    // Duplicate email is rejected with a friendly error.
    $this->post(centralUrl('signup'), ['salon_name' => 'Other', 'owner_name' => 'X', 'email' => 'dana@velvet.test', 'password' => 'very-secret-pass', 'plan' => 'essential', 'terms' => '1'])
        ->assertSessionHasErrors('email');
});

it('captures demo requests as new leads', function () {
    $this->post(centralUrl('demo'), [
        'name' => 'Hessa', 'email' => 'hessa@atelier.test', 'phone' => '+97455550000', 'salon_name' => 'Atelier Noor', 'branches' => 3,
        'preferred_time' => 'Sunday morning', 'message' => 'Interested in AI Studio',
    ])->assertRedirect(centralUrl('demo'));

    $lead = Lead::firstOrFail();
    expect($lead->stage)->toBe('new')->and($lead->source)->toBe('demo')->and($lead->branches)->toBe(3)
        ->and($lead->message)->toContain('Sunday morning')->and($lead->message)->toContain('Interested in AI Studio');

    // Honeypot blocks bots.
    $this->post(centralUrl('demo'), ['name' => 'Bot', 'email' => 'bot@x.test', 'website' => 'spam'])->assertSessionHasErrors('website');
    expect(Lead::count())->toBe(1);
});

it('serves the platform sitemap and robots.txt', function () {
    BlogPost::create(['slug' => 'trends-2026', 'title' => ['en' => 'Trends'], 'published_at' => now()->subDay()]);
    BlogPost::create(['slug' => 'draft', 'title' => ['en' => 'Draft']]);

    $this->get(centralUrl('sitemap.xml'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8')
        ->assertSee('<loc>'.centralUrl('pricing').'</loc>', false)
        ->assertSee(centralUrl('features/booking'), false)
        ->assertSee(centralUrl('blog/trends-2026'), false)
        ->assertDontSee('blog/draft', false)
        ->assertSee('hreflang="ar"', false);

    $this->get(centralUrl('robots.txt'))->assertOk()->assertSee('Disallow: /admin')->assertSee('Sitemap: '.centralUrl('sitemap.xml'));
});

it('renders the website CMS in /admin and saves a section-based page', function () {
    $this->actingAs(platformAdmin());
    $page = PlatformPage::create(['slug' => 'home', 'title' => ['en' => 'Home'], 'sections' => PlatformContent::defaults()['home']['sections']]);
    BlogPost::create(['slug' => 'p1', 'title' => ['en' => 'P1'], 'published_at' => now()]);

    foreach (['admin/website-pages', 'admin/website-pages/create', "admin/website-pages/{$page->id}/edit", 'admin/blog-posts'] as $path) {
        $this->get(centralUrl($path))->assertOk();
    }

    Filament::setCurrentPanel('admin');
    Livewire::test(CreatePlatformPage::class)
        ->fillForm(['title' => ['en' => 'Careers', 'ar' => 'الوظائف'], 'slug' => 'careers', 'is_published' => true])
        ->call('create')->assertHasNoFormErrors();
    expect(PlatformPage::where('slug', 'careers')->exists())->toBeTrue();
});
