<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Contracts\ModeratesContent;
use App\Domain\AIStudio\Jobs\RunAIJob;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\AITaskPrice;
use App\Domain\AIStudio\Models\ClientLook;
use App\Domain\AIStudio\Support\AIRouter;
use App\Domain\AIStudio\Support\AtelierCatalog;
use App\Domain\AIStudio\Support\PhotoVault;
use App\Domain\CRM\Models\Client;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Exceptions\DomainException;

/**
 * Live Atelier → "Make it real": the client's photo plus the design drawn on it (placement guide)
 * go to the image AI with a prompt built from the catalog. Same gates as a lookbook try-on:
 * consent, minors, moderation, quota, salon wallet.
 */
class RequestAtelierRender
{
    public function __construct(
        protected PhotoVault $vault,
        protected ClientQuota $quota,
        protected ModeratesContent $moderation,
    ) {}

    public function handle(Tenant $tenant, string $category, array $params, string $photo, ?string $guide, ?Client $client, ?string $device, string $source = 'atelier', ?string $locale = null): AIJob
    {
        if (! AtelierCatalog::has($category)) {
            throw new DomainException(__('ai_studio.errors.style_unavailable'));
        }
        $consent = RecordConsent::current($client, $device);
        if (! $consent) {
            throw new DomainException(__('ai_studio.errors.consent_required'));
        }
        if ($consent->is_minor && AtelierCatalog::CATEGORIES[$category]['face']) {
            throw new DomainException(__('ai_studio.errors.minors_blocked'));
        }
        $price = AITaskPrice::for(AtelierCatalog::CATEGORIES[$category]['task']);
        if (app(AIRouter::class)->candidates($price->task, $price->capability ?: 'image', $tenant->id)->isEmpty()) {
            throw new DomainException(__('ai_studio.errors.tryon_unavailable'));
        }
        if ($reason = $this->moderation->check($photo, 'input')) {
            throw new DomainException($reason);
        }

        RequestTryOn::assertWalletCovers($tenant, (int) $price->credits);
        $subject = ClientQuota::subject($client, $device);
        $this->quota->assertCanUse($tenant, $subject, $client);

        $params = AtelierCatalog::normalize($category, $params);
        $path = $this->vault->put($tenant->id, $photo, 'before');
        $guidePath = $guide ? $this->vault->put($tenant->id, $guide, 'guide') : null;

        $job = AIJob::create([
            'client_id' => $client?->id,
            'task' => $price->task,
            'source' => $source,
            'input_refs' => array_filter(['photo' => $path, 'mime' => PhotoVault::mime($photo), 'guide' => $guidePath, 'subject' => $subject]),
            'prompt' => AtelierCatalog::prompt($category, $params),
            'cost_credits' => (int) $price->credits,
            'status' => 'queued',
            'device_fingerprint' => $device,
            'locale' => $locale ?? app()->getLocale(),
        ]);

        ClientLook::create([
            'client_id' => $client?->id,
            'ai_job_id' => $job->id,
            'category' => $category,
            'atelier' => ['category' => $category, 'params' => $params, 'summary' => AtelierCatalog::summary($category, $params, 'en'),
                'service_id' => AtelierCatalog::serviceFor($category)?->id],
            'before_path' => $path,
            'device_fingerprint' => $device,
            'expires_at' => now()->addDays((int) $tenant->setting('ai.retention_days', config('ai.retention_days'))),
        ]);

        RunAIJob::dispatch($tenant->id, $job->id);

        return $job->fresh();
    }
}
