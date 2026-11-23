<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Contracts\ModeratesContent;
use App\Domain\AIStudio\Jobs\RunAIJob;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\AITaskPrice;
use App\Domain\AIStudio\Support\PhotoVault;
use App\Domain\CRM\Models\Client;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * AI consultant: a vision/text model reads face shape, skin tone, undertone and hair texture and
 * picks 3 flattering looks from the salon's style library, with reasons (ar/en). Adults only.
 */
class RequestConsultation
{
    public function __construct(protected PhotoVault $vault, protected ModeratesContent $moderation) {}

    public function handle(Tenant $tenant, string $photo, ?Client $client, ?string $device, ?string $locale = null): AIJob
    {
        $consent = RecordConsent::current($client, $device);
        if (! $consent) {
            throw new DomainException(__('ai_studio.errors.consent_required'));
        }
        if ($consent->is_minor) {
            throw new DomainException(__('ai_studio.errors.minors_blocked'));
        }
        if ($reason = $this->moderation->check($photo, 'input')) {
            throw new DomainException($reason);
        }
        $key = 'ai-consult:'.$tenant->id.':'.ClientQuota::subject($client, $device);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new DomainException(__('ai_studio.errors.rate_limited'));
        }
        RateLimiter::hit($key, 3600);

        $price = AITaskPrice::for('consultant');
        RequestTryOn::assertWalletCovers($tenant, (int) $price->credits);

        $job = AIJob::create([
            'client_id' => $client?->id,
            'task' => 'consultant',
            'source' => 'consultant',
            'input_refs' => ['photo' => $this->vault->put($tenant->id, $photo, 'consult'), 'mime' => PhotoVault::mime($photo)],
            'cost_credits' => (int) $price->credits,
            'status' => 'queued',
            'device_fingerprint' => $device,
            'locale' => $locale ?? app()->getLocale(),
        ]);

        RunAIJob::dispatch($tenant->id, $job->id);

        return $job->fresh();
    }
}
