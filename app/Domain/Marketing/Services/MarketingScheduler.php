<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Marketing\Actions\DecideAbWinner;
use App\Domain\Marketing\Actions\LaunchCampaign;
use App\Domain\Marketing\Automation\AutomationEngine;
use App\Domain\Marketing\Automation\TriggerRegistry;
use App\Domain\Marketing\Jobs\SendCampaignBatch;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Domain\Marketing\Models\ClientMembership;
use App\Domain\Marketing\Models\ClientPackage;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent scheduler entry points (routes/schedules/marketing.php). Each tenant is processed inside
 * TenantContext::run; everything is safe to run twice (status checks + idempotency keys).
 */
class MarketingScheduler
{
    public function __construct(protected TenantContext $context) {}

    /** Every 5 minutes: due scheduled campaigns, quiet-hours deferrals, A/B decisions, automation waits. */
    public function frequent(): void
    {
        $this->eachTenant(function () {
            Campaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->get()
                ->each(fn (Campaign $c) => rescue(fn () => app(LaunchCampaign::class)->handle($c), fn ($e) => $this->failCampaign($c, $e)));

            CampaignMessage::where('status', 'skipped_quiet_hours')->where('scheduled_for', '<=', now())->pluck('id')->chunk(50)
                ->each(fn ($ids) => SendCampaignBatch::dispatch(tenant()->id, $ids->values()->all()));

            Campaign::where('status', 'testing')->whereNull('ab_decided_at')->get()
                ->each(fn (Campaign $c) => app(DecideAbWinner::class)->handle($c));

            app(AutomationEngine::class)->resumeDue();
        });
    }

    /** Daily: time-based automation triggers + package/membership expiry. */
    public function daily(): void
    {
        $this->eachTenant(function () {
            ClientPackage::where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<', now())->update(['status' => 'expired']);
            ClientMembership::where('status', 'active')->where('auto_renew', false)->whereNotNull('renews_at')->where('renews_at', '<', now())->update(['status' => 'expired']);
            app(AutomationEngine::class)->scan(app(TriggerRegistry::class));
        });
    }

    protected function eachTenant(\Closure $callback): void
    {
        Tenant::query()->whereIn('status', ['active', 'trial', 'grace'])->each(function (Tenant $tenant) use ($callback) {
            if (! $tenant->hasModule('marketing')) {
                return;
            }
            try {
                $this->context->run($tenant, $callback);
            } catch (\Throwable $e) {
                Log::error('marketing scheduler failed for tenant '.$tenant->id.': '.$e->getMessage());
                report($e);
            }
        });
    }

    protected function failCampaign(Campaign $campaign, \Throwable $e): void
    {
        $campaign->update(['status' => 'failed']);
        activity()->performedOn($campaign)->withProperties(['error' => $e->getMessage()])->log('campaign.failed');
    }
}
