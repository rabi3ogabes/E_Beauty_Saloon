<?php

use App\Domain\Marketing\Models\Automation;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\Review;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Tenancy\Models\Tenant;
use Database\Seeders\Demo\MarketingDemoSeeder;
use Database\Seeders\DemoTenantSeeder;
use Illuminate\Database\Eloquent\Model;

it('seeds the lumiere marketing demo and renders every screen without lazy loading', function () {
    MessagingManager::fake();
    $this->seed(DemoTenantSeeder::class);
    $this->seed(MarketingDemoSeeder::class);
    $tenant = Tenant::where('slug', 'lumiere')->firstOrFail();

    asTenant($tenant, function () {
        expect(Campaign::where('status', 'sent')->count())->toBe(3)
            ->and(Review::count())->toBe(40)
            ->and(Automation::where('is_active', true)->count())->toBeGreaterThan(5);
    });

    Model::preventLazyLoading();
    try {
        $campaign = asTenant($tenant, fn () => Campaign::where('status', 'sent')->first());
        $automation = asTenant($tenant, fn () => Automation::first());
        $paths = ['dashboard/marketing/campaigns', 'dashboard/marketing/campaigns/'.$campaign->id, 'dashboard/marketing/segments', 'dashboard/marketing/automations',
            'dashboard/marketing/automations/'.$automation->id.'/flow', 'dashboard/marketing/banners', 'dashboard/marketing/whatsapp-templates',
            'dashboard/marketing/packages', 'dashboard/marketing/client-packages', 'dashboard/marketing/memberships', 'dashboard/marketing/promotions',
            'dashboard/marketing/reviews', 'dashboard/marketing/referrals', 'dashboard/marketing/loyalty', 'dashboard/marketing/channels', 'dashboard/marketing/gift-card-orders'];
        foreach ($paths as $path) {
            $this->actingAs($tenant->owner)->get(tenantUrl($tenant, $path))->assertOk();
        }
        $this->get(tenantUrl($tenant, 'packages'))->assertOk()->assertSee('Bridal Glow');
        $this->get(tenantUrl($tenant, 'gift-cards'))->assertOk();
    } finally {
        Model::preventLazyLoading(false);
    }
});
