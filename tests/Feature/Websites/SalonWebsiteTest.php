<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\Websites\Actions\EnsureHomePage;
use App\Domain\Websites\Actions\OptimizeImage;
use App\Domain\Websites\Actions\UpdateSiteDesign;
use App\Domain\Websites\Contracts\WebPushSender;
use App\Domain\Websites\Models\PushSubscription;
use App\Domain\Websites\Models\SitePage;
use App\Domain\Websites\Models\SiteSection;
use App\Domain\Websites\Support\SectionRegistry;
use App\Filament\Dashboard\Pages\Website\WebsiteDesign;
use App\Filament\Dashboard\Pages\Website\WebsiteSettings;
use App\Support\Exceptions\DomainException;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = createTenant(slug: 'lumiere');
    asTenant($this->tenant, function () {
        $cat = ServiceCategory::create(['name' => ['en' => 'Hair', 'ar' => 'الشعر'], 'slug' => 'hair']);
        $service = Service::create(['service_category_id' => $cat->id, 'name' => ['en' => 'Balayage', 'ar' => 'بالياج'], 'slug' => 'balayage', 'duration_minutes' => 180, 'price' => 120000]);
        $branch = Branch::where('slug', 'main')->first();
        $branch->update(['area' => 'West Bay', 'city' => 'Doha', 'lat' => 25.32, 'lng' => 51.53, 'phone' => '+97444001122', 'address' => ['en' => 'Tower 3, West Bay', 'ar' => 'البرج 3']]);
        $service->branches()->attach($branch->id, ['is_active' => true]);
    });
});

it('renders a stunning salon home from built-in defaults', function () {
    $this->get(tenantUrl($this->tenant, '/'))->assertOk()
        ->assertSee('class="theme-maison', false)
        ->assertSee('--brand-primary: #C8A96A', false)
        ->assertSee('<h1', false)
        ->assertSee('Balayage')
        ->assertSee('BeautySalon', false)
        ->assertSee('data-map', false)
        ->assertSee('rel="manifest"', false)
        ->assertSee('See the look before you book it')
        ->assertSee('/studio', false);

    $this->get(tenantUrl($this->tenant, '/?lang=ar'))->assertOk()->assertSee('dir="rtl"', false)->assertSee('بالياج');
});

it('owner changes theme and colors and the site updates without deploy', function () {
    $this->get(tenantUrl($this->tenant, '/'))->assertSee('theme-maison', false);

    asTenant($this->tenant, fn () => app(UpdateSiteDesign::class)->handle($this->tenant, ['theme' => 'noir', 'primary' => '#B76E79', 'font_display' => 'Cormorant Garamond']));

    $this->get(tenantUrl($this->tenant, '/'))->assertOk()
        ->assertSee('class="theme-noir dark"', false)
        ->assertSee('--brand-primary: #B76E79', false)
        ->assertSee("--brand-font-display: 'Cormorant Garamond'", false);

    // Same flow through the dashboard design page (Filament).
    asTenant($this->tenant);
    Filament::setCurrentPanel('dashboard');
    $this->actingAs($this->tenant->owner);
    Livewire::test(WebsiteDesign::class)->set('data.theme', 'rose')->set('data.primary', '#123456')->call('save')->assertHasNoErrors();
    app(TenantContext::class)->set(null);

    $this->get(tenantUrl($this->tenant, '/'))->assertSee('theme-rose', false)->assertSee('--brand-primary: #123456', false);
});

it('rejects unsafe design tokens', function () {
    asTenant($this->tenant, fn () => app(UpdateSiteDesign::class)->handle($this->tenant, ['primary' => 'red;}</style><script>alert(1)</script>']));
})->throws(DomainException::class);

it('renders builder sections in order and hides invisible ones', function () {
    $page = asTenant($this->tenant, function () {
        $page = app(EnsureHomePage::class)->handle($this->tenant);
        $page->sections()->delete();
        $page->sections()->create(['type' => 'text', 'content' => ['en' => ['title' => 'Our philosophy', 'body' => 'Quiet luxury.'], 'ar' => ['title' => 'فلسفتنا']], 'sort' => 1]);
        $page->sections()->create(['type' => 'hero', 'content' => ['en' => ['title' => 'Couture beauty']], 'sort' => 0]);
        $page->sections()->create(['type' => 'faq', 'content' => ['en' => ['title' => 'FAQ', 'items' => [['q' => 'Parking?', 'a' => 'Valet available.']]]], 'is_visible' => false, 'sort' => 2]);

        return $page;
    });

    $html = $this->get(tenantUrl($this->tenant, '/'))->assertOk()->assertSeeInOrder(['Couture beauty', 'Our philosophy'])->assertDontSee('Valet available.')->getContent();
    expect(substr_count($html, '<h1'))->toBe(1);

    $this->get(tenantUrl($this->tenant, '/?lang=ar'))->assertSee('فلسفتنا');
    expect($page->sections()->count())->toBe(3);
});

it('renders reviews only when the marketing component exists', function () {
    asTenant($this->tenant, fn () => app(EnsureHomePage::class)->handle($this->tenant));
    $section = new SiteSection(['type' => 'reviews', 'is_visible' => true]);

    expect(SectionRegistry::available($section, $this->tenant))->toBe(View::exists('components.marketing.reviews'));
    $this->get(tenantUrl($this->tenant, '/'))->assertOk();
});

it('serves a branch landing page with BeautySalon JSON-LD', function () {
    $res = $this->get(tenantUrl($this->tenant, 'branches/main'))->assertOk()
        ->assertSee(__('website.branch.h1', ['area' => 'West Bay']))
        ->assertSee('<title>'.e(__('website.branch.seo_title', ['area' => 'West Bay', 'salon' => $this->tenant->name])), false);

    preg_match_all('#<script type="application/ld\+json">(.+?)</script>#s', $res->getContent(), $m);
    $ld = collect($m[1])->map(fn ($j) => json_decode($j, true))->first(fn ($j) => in_array('BeautySalon', (array) ($j['@type'] ?? []), true));

    expect($ld)->not->toBeNull()
        ->and($ld['@type'])->toContain('LocalBusiness')
        ->and($ld['address']['addressRegion'])->toBe('West Bay')
        ->and($ld['geo']['latitude'])->toBe(25.32)
        ->and($ld['openingHoursSpecification'])->toHaveCount(7)
        ->and($ld['makesOffer'][0]['itemOffered']['name'])->toBe('Balayage')
        ->and($ld['potentialAction']['target'])->toContain('/book?branch=main');

    $this->get(tenantUrl($this->tenant, 'branches/nope'))->assertNotFound();
});

it('serves the tenant sitemap, robots and PWA manifest', function () {
    asTenant($this->tenant, fn () => SitePage::create(['slug' => 'bridal', 'title' => ['en' => 'Bridal'], 'is_published' => true]));

    $this->get(tenantUrl($this->tenant, 'sitemap.xml'))->assertOk()
        ->assertSee(tenantUrl($this->tenant, 'branches/main'), false)
        ->assertSee(tenantUrl($this->tenant, 'pages/bridal'), false)
        ->assertSee(tenantUrl($this->tenant, 'studio'), false)
        ->assertDontSee('features/booking');

    $this->get(tenantUrl($this->tenant, 'robots.txt'))->assertOk()->assertSee('Disallow: /dashboard')->assertSee('Sitemap: '.tenantUrl($this->tenant, 'sitemap.xml'));

    $this->getJson(tenantUrl($this->tenant, 'manifest.webmanifest'))->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('name', $this->tenant->name)
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('theme_color', '#C8A96A')
        ->assertJsonPath('icons.0.src', tenantUrl($this->tenant, 'pwa/icon-192.png'));

    $icon = $this->get(tenantUrl($this->tenant, 'pwa/icon-512.png'))->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(getimagesizefromstring($icon->getContent())[0])->toBe(512);

    $sw = $this->get(tenantUrl($this->tenant, 'sw.js'))->assertOk()->assertSee("const OFFLINE = '/offline'", false);
    expect($sw->headers->get('Content-Type'))->toStartWith('application/javascript');
    $this->get(tenantUrl($this->tenant, 'offline'))->assertOk();
});

it('stores web push subscriptions per tenant', function () {
    $payload = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123', 'keys' => ['p256dh' => 'BPubKey', 'auth' => 'AuthSecret']];

    $this->postJson(tenantUrl($this->tenant, 'push/subscribe'), $payload)->assertCreated();
    $this->postJson(tenantUrl($this->tenant, 'push/subscribe'), $payload)->assertCreated();

    expect(PushSubscription::count())->toBe(1)->and(PushSubscription::first()->tenant_id)->toBe($this->tenant->id);
    expect(app(WebPushSender::class)->send(PushSubscription::first(), ['title' => 'Hi']))->toBeFalse();

    $this->postJson(tenantUrl($this->tenant, 'push/subscribe'), ['endpoint' => 'http://insecure.test/x'])->assertUnprocessable();
});

it('converts uploads to responsive WebP', function () {
    Storage::fake('public');
    $img = imagecreatetruecolor(2000, 1200);
    ob_start();
    imagejpeg($img);
    Storage::disk('public')->put('tenants/1/brand/hero.jpg', ob_get_clean());

    $path = app(OptimizeImage::class)->handle('tenants/1/brand/hero.jpg');

    expect($path)->toBe('tenants/1/brand/hero.webp');
    foreach ([480, 960, 1600] as $w) {
        expect(Storage::disk('public')->exists("tenants/1/brand/hero-{$w}.webp"))->toBeTrue();
    }
    expect(Storage::disk('public')->exists('tenants/1/brand/hero.jpg'))->toBeFalse();
});

it('injects only validated analytics IDs', function () {
    $settings = $this->tenant->settings;
    $settings['website'] = ['ga4_id' => 'G-ABC123XYZ', 'meta_pixel_id' => '1234567890', 'tiktok_pixel_id' => '<script>bad</script>'];
    $this->tenant->update(['settings' => $settings]);

    $this->get(tenantUrl($this->tenant, '/'))->assertOk()->assertSee('G-ABC123XYZ')->assertSee('1234567890')->assertDontSee('<script>bad</script>', false);
});

it('renders the website dashboard pages and saves settings', function () {
    $owner = $this->tenant->owner;
    foreach (['dashboard/website/pages', 'dashboard/website/design', 'dashboard/website/settings'] as $path) {
        $this->actingAs($owner)->get(tenantUrl($this->tenant, $path))->assertOk();
    }
    $page = asTenant($this->tenant, fn () => app(EnsureHomePage::class)->handle($this->tenant));
    $this->actingAs($owner)->get(tenantUrl($this->tenant, 'dashboard/website/pages/'.$page->id.'/edit'))->assertOk();

    asTenant($this->tenant);
    Filament::setCurrentPanel('dashboard');
    $this->actingAs($owner);
    Livewire::test(WebsiteSettings::class)->set('data.ga4_id', 'G-TEST1234')->set('data.gbp_booking_url', 'https://g.page/r/abc/book')->call('save')->assertHasNoErrors();
    expect($this->tenant->fresh()->setting('website.ga4_id'))->toBe('G-TEST1234');

    Livewire::test(WebsiteSettings::class)->set('data.ga4_id', 'not-an-id')->call('save')->assertHasErrors(['data.ga4_id']);
});

it('blocks the website builder for staff without permission', function () {
    $stylist = tenantUser($this->tenant, 'stylist');
    $this->actingAs($stylist)->get(tenantUrl($this->tenant, 'dashboard/website/design'))->assertForbidden();
});
