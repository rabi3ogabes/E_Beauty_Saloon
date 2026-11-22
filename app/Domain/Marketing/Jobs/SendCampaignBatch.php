<?php

namespace App\Domain\Marketing\Jobs;

use App\Domain\Marketing\Actions\CompleteCampaign;
use App\Domain\Marketing\Actions\SendCampaignMessage;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

/**
 * Throttled campaign delivery: a small batch of campaign_messages per job, rate limited per tenant
 * (limiter "marketing-sends", tenant setting marketing.rate_per_minute, default 600/min).
 */
class SendCampaignBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $tenantId, public array $messageIds)
    {
        $this->onQueue('marketing');
    }

    public function middleware(): array
    {
        return [new RateLimited('marketing-sends')];
    }

    public function handle(TenantContext $context, SendCampaignMessage $sender): void
    {
        $context->run(Tenant::find($this->tenantId), function () use ($sender) {
            $campaigns = [];
            CampaignMessage::with(['campaign', 'client.consents', 'variant'])->whereIn('id', $this->messageIds)->each(function (CampaignMessage $m) use ($sender, &$campaigns) {
                $sender->handle($m);
                $campaigns[$m->campaign_id] = $m->campaign;
            });
            foreach ($campaigns as $campaign) {
                CompleteCampaign::check($campaign->fresh());
            }
        });
    }
}
