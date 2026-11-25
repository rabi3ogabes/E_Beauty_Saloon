<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Contracts\ModeratesContent;
use App\Domain\AIStudio\Jobs\RunAIJob;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\AITaskPrice;
use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\AIStudio\Models\ClientLook;
use App\Domain\AIStudio\Models\Style;
use App\Domain\AIStudio\Support\AIRouter;
use App\Domain\AIStudio\Support\PhotoVault;
use App\Domain\CRM\Models\Client;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Exceptions\DomainException;

/**
 * Client asks to try a style on their photo. Runs every safety gate (consent, minors, moderation,
 * quota/rate limit, salon wallet hard stop), stores the photo encrypted and queues the AI job.
 */
class RequestTryOn
{
    public function __construct(
        protected PhotoVault $vault,
        protected ClientQuota $quota,
        protected ModeratesContent $moderation,
    ) {}

    public function handle(Tenant $tenant, Style $style, string $photo, ?Client $client, ?string $device, string $source = 'studio', ?string $locale = null): AIJob
    {
        $consent = RecordConsent::current($client, $device);
        if (! $consent) {
            throw new DomainException(__('ai_studio.errors.consent_required'));
        }
        if ($consent->is_minor && $style->isFaceFeature()) {
            throw new DomainException(__('ai_studio.errors.minors_blocked'));
        }
        if (! $style->is_active) {
            throw new DomainException(__('ai_studio.errors.style_unavailable'));
        }
        $price = AITaskPrice::for($style->task ?: (Style::CATEGORIES[$style->category]['task'] ?? 'hair_tryon'));
        // No image AI configured (or every provider is down): say so now instead of queueing a look that can't run.
        if (app(AIRouter::class)->candidates($price->task, $price->capability ?: 'image', $tenant->id)->isEmpty()) {
            throw new DomainException(__('ai_studio.errors.tryon_unavailable'));
        }
        if ($reason = $this->moderation->check($photo, 'input')) {
            throw new DomainException($reason);
        }

        self::assertWalletCovers($tenant, (int) $price->credits);

        $subject = ClientQuota::subject($client, $device);
        $this->quota->assertCanUse($tenant, $subject, $client);

        $path = $this->vault->put($tenant->id, $photo, 'before');

        $job = AIJob::create([
            'client_id' => $client?->id,
            'task' => $price->task,
            'source' => $source,
            'style_id' => $style->id,
            'input_refs' => ['photo' => $path, 'mime' => PhotoVault::mime($photo), 'subject' => $subject],
            'prompt' => self::prompt($style),
            'cost_credits' => (int) $price->credits,
            'status' => 'queued',
            'device_fingerprint' => $device,
            'locale' => $locale ?? app()->getLocale(),
        ]);

        ClientLook::create([
            'client_id' => $client?->id,
            'ai_job_id' => $job->id,
            'style_id' => $style->id,
            'category' => $style->category,
            'before_path' => $path,
            'device_fingerprint' => $device,
            'expires_at' => now()->addDays((int) $tenant->setting('ai.retention_days', config('ai.retention_days'))),
        ]);

        RunAIJob::dispatch($tenant->id, $job->id);

        return $job->fresh();
    }

    /** Hard stop at zero: the salon wallet must cover the task before anything is queued. */
    public static function assertWalletCovers(Tenant $tenant, int $credits): void
    {
        if (AIWallet::for($tenant)->balance < $credits) {
            throw new DomainException(__('ai_studio.errors.salon_out_of_credits'));
        }
    }

    public static function prompt(Style $style): string
    {
        $framing = match (true) {
            $style->category === 'extensions' => 'the hair only (add the length, volume or ponytail described, blended seamlessly and matched to the person\'s natural hair colour)',
            $style->framing() === 'hand' => 'the hands and nails',
            $style->framing() === 'foot' => 'the feet and toenails',
            default => 'the face and hair',
        };

        return trim($style->prompt)."\n\nEdit only {$framing} in the provided photo. Keep the person's identity, facial features, skin texture, "
            .'body, clothing, lighting and background unchanged. Photorealistic salon result, natural light, no text, no watermark.';
    }
}
