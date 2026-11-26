<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Contracts\ModeratesContent;
use App\Domain\AIStudio\Jobs\RunAIJob;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\AITaskPrice;
use App\Domain\AIStudio\Support\AIRouter;
use App\Domain\AIStudio\Support\AtelierCatalog;
use App\Domain\AIStudio\Support\PhotoVault;
use App\Domain\CRM\Models\Client;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Live Atelier → "Ask our AI artist": a vision model reads the photo and recommends the design
 * (from the catalog only) for one category, with a short reason. Text task, charged on success.
 */
class RequestAtelierAdvice
{
    public function __construct(protected PhotoVault $vault, protected ModeratesContent $moderation) {}

    public function handle(Tenant $tenant, string $category, string $photo, ?Client $client, ?string $device, ?string $locale = null): AIJob
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
        $price = AITaskPrice::for('atelier_advice');
        if (app(AIRouter::class)->candidates('atelier_advice', $price->capability ?: 'text', $tenant->id)->isEmpty()) {
            throw new DomainException(__('ai_studio.atelier.advice_unavailable'));
        }
        if ($reason = $this->moderation->check($photo, 'input')) {
            throw new DomainException($reason);
        }
        $key = 'ai-atelier-advice:'.$tenant->id.':'.ClientQuota::subject($client, $device);
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw new DomainException(__('ai_studio.errors.rate_limited'));
        }
        RateLimiter::hit($key, 3600);
        RequestTryOn::assertWalletCovers($tenant, (int) $price->credits);

        $job = AIJob::create([
            'client_id' => $client?->id,
            'task' => 'atelier_advice',
            'source' => 'atelier',
            'input_refs' => ['photo' => $this->vault->put($tenant->id, $photo, 'consult'), 'mime' => PhotoVault::mime($photo), 'category' => $category],
            'cost_credits' => (int) $price->credits,
            'status' => 'queued',
            'device_fingerprint' => $device,
            'locale' => $locale ?? app()->getLocale(),
        ]);

        RunAIJob::dispatch($tenant->id, $job->id);

        return $job->fresh();
    }
}
