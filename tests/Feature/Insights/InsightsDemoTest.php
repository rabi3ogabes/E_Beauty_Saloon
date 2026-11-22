<?php

use App\Domain\Assistant\Models\Conversation;
use App\Domain\Insights\Models\ConsultationForm;
use App\Domain\Insights\Models\InsightCard;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Tenancy\Models\Tenant;
use Database\Seeders\Demo\BookingDemoSeeder;
use Database\Seeders\Demo\InsightsDemoSeeder;
use Database\Seeders\DemoTenantSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

it('seeds the lumiere smart-layer demo and renders every screen in English and Arabic', function () {
    MessagingManager::fake();
    Storage::fake('local');
    $this->seed(DemoTenantSeeder::class);
    $this->seed(BookingDemoSeeder::class);
    $this->seed(InsightsDemoSeeder::class);
    $this->seed(InsightsDemoSeeder::class); // idempotent
    $tenant = Tenant::where('slug', 'lumiere')->firstOrFail();
    $tenant->forceFill(['onboarded_at' => now()])->save();

    asTenant($tenant, fn () => expect(InsightCard::count())->toBeGreaterThan(0)
        ->and(Conversation::count())->toBe(2)
        ->and(ConsultationForm::where('status', 'signed')->count())->toBe(1));

    $paths = ['dashboard', 'dashboard/insights', 'dashboard/insights?tab=churn', 'dashboard/insights?tab=forecast', 'dashboard/insights?tab=reorder',
        'dashboard/insights?tab=pricing', 'dashboard/insights?tab=ask', 'dashboard/reports/revenue', 'dashboard/reports/performance',
        'dashboard/reports/scorecards', 'dashboard/reports/campaigns', 'dashboard/reports/overbookings', 'dashboard/inbox', 'dashboard/consultations',
        'dashboard/settings/insights', 'dashboard/settings/receptionist', 'dashboard/ai-studio/content'];

    Model::preventLazyLoading();
    try {
        foreach (['en', 'ar'] as $locale) {
            $tenant->owner->update(['locale' => $locale]);
            foreach ($paths as $path) {
                $this->actingAs($tenant->owner)->withSession(['locale' => $locale])->get(tenantUrl($tenant, $path))->assertOk();
            }
        }
        $this->get(tenantUrl($tenant, 'dashboard/insights'))->assertSee('الرؤى الذكية');
        $form = asTenant($tenant, fn () => ConsultationForm::where('status', 'sent')->firstOrFail());
        $this->get(tenantUrl($tenant, 'consultation/'.$form->token))->assertOk();
    } finally {
        Model::preventLazyLoading(false);
    }
});
