<?php

namespace App\Domain\AIStudio;

use App\Domain\AIStudio\Actions\ProcessAIJob;
use App\Domain\AIStudio\Actions\RequestTryOn;
use App\Domain\AIStudio\Contracts\GeneratesMarketingContent;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\AITaskPrice;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\Storage;

/**
 * Real implementation of the marketing hook: banner visuals via the image router, bilingual copy via
 * the text router. Runs synchronously (marketing UI waits) and charges credits only on success.
 */
class AIMarketingContentGenerator implements GeneratesMarketingContent
{
    public function __construct(protected ProcessAIJob $process) {}

    public function bannerVisual(string $prompt, string $aspect = '1:1'): array
    {
        try {
            $job = $this->run('marketing_banner', $prompt, ['aspect' => $aspect]);
        } catch (DomainException $e) {
            return ['image_url' => null, 'error' => $e->getMessage(), 'credits_used' => 0];
        }

        return $job->status === 'succeeded'
            ? ['image_url' => Storage::disk('public')->url(data_get($job->output_refs, 'image')), 'error' => null, 'credits_used' => (int) $job->charged_credits]
            : ['image_url' => null, 'error' => $job->error ?: __('ai_studio.errors.all_providers_failed'), 'credits_used' => 0];
    }

    public function copy(string $brief, string $tone = 'luxury'): array
    {
        try {
            $job = $this->run('marketing_copy', $brief, ['tone' => $tone]);
        } catch (DomainException) {
            return ['en' => $brief, 'ar' => $brief];
        }

        return $job->status === 'succeeded'
            ? ['en' => (string) data_get($job->output_refs, 'en', $brief), 'ar' => (string) data_get($job->output_refs, 'ar', $brief)]
            : ['en' => $brief, 'ar' => $brief];
    }

    protected function run(string $task, string $prompt, array $input): AIJob
    {
        $tenant = tenant();
        if (! $tenant?->hasModule('ai_studio')) {
            throw new DomainException(__('salon.errors.module_disabled'));
        }
        $price = AITaskPrice::for($task);
        RequestTryOn::assertWalletCovers($tenant, (int) $price->credits);

        $job = AIJob::create([
            'user_id' => auth()->id(),
            'task' => $task,
            'source' => 'marketing',
            'input_refs' => $input,
            'prompt' => $task === 'marketing_banner'
                ? trim($prompt).'. Luxury beauty salon advertising visual, editorial photography, elegant negative space for text, no words or logos.'
                : $prompt,
            'cost_credits' => (int) $price->credits,
            'status' => 'queued',
            'locale' => app()->getLocale(),
        ]);

        return $this->process->handle($job);
    }
}
