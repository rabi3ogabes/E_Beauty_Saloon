<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Contracts\ModeratesContent;
use App\Domain\AIStudio\DTOs\ImageRequest;
use App\Domain\AIStudio\DTOs\ImageResult;
use App\Domain\AIStudio\Events\AIJobUpdated;
use App\Domain\AIStudio\Exceptions\NoProviderAvailable;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\AITaskPrice;
use App\Domain\AIStudio\Models\ClientLook;
use App\Domain\AIStudio\Models\Style;
use App\Domain\AIStudio\Providers\AbstractDriver;
use App\Domain\AIStudio\Support\AIRouter;
use App\Domain\AIStudio\Support\PhotoVault;
use App\Domain\AIStudio\Support\RouteResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Executes an AI job: route to a provider (with fallback), charge credits ONLY on success,
 * then persist + moderate the output — any failure after the charge refunds it.
 */
class ProcessAIJob
{
    public function __construct(
        protected AIRouter $router,
        protected CreditLedger $ledger,
        protected PhotoVault $vault,
        protected ModeratesContent $moderation,
        protected ClientQuota $quota,
    ) {}

    public function handle(AIJob $job): AIJob
    {
        $tenant = tenant() ?? $job->tenant;
        $job->update(['status' => 'running', 'attempts' => $job->attempts + 1, 'started_at' => now()]);
        $this->broadcast($job);

        $capability = AITaskPrice::for($job->task)->capability ?: 'image';

        try {
            $route = $this->router->run($job->task, $capability, fn (AbstractDriver $driver) => $this->perform($job, $driver), $tenant->id);
        } catch (NoProviderAvailable $e) {
            $job->update(['status' => 'failed', 'error' => $e->getMessage(), 'attempt_log' => $e->attempts, 'finished_at' => now()]);
            $this->broadcast($job);

            return $job;
        }

        // 1) Success from a provider → charge credits (idempotent per job).
        $this->ledger->debit($tenant, $job->cost_credits, 'charge', 'job:'.$job->id.':charge',
            __('ai_studio.wallet.charge', ['task' => $job->task]), 'ai_job', $job->id, allowNegative: true);
        $job->update([
            'ai_provider_id' => $route->provider->id,
            'provider_driver' => $route->provider->driver,
            'model' => $route->provider->model($capability),
            'charged_credits' => $job->cost_credits,
            'provider_cost' => $route->provider->unitCost($capability) * ($job->task === 'consultant' ? 2 : 1),
            'latency_ms' => $route->latencyMs,
            'attempt_log' => $route->attempts,
        ]);

        // 2) Persist + moderate output. Anything failing here refunds the charge.
        try {
            $output = $this->persist($job, $route);
        } catch (Throwable $e) {
            $this->refund($job, $e->getMessage());

            return $job->fresh();
        }

        $job->update(['status' => 'succeeded', 'output_refs' => $output, 'finished_at' => now(), 'error' => null]);

        if ($subject = data_get($job->input_refs, 'subject')) {
            $this->quota->consume($job->tenant_id, $subject, $job->client_id);
        }
        activity()->performedOn($job)->withProperties(['task' => $job->task, 'provider' => $route->provider->driver, 'credits' => $job->cost_credits])->log('ai.job.succeeded');
        $this->broadcast($job);

        return $job;
    }

    /** Refund a charged job (idempotent) and mark it refunded. */
    public function refund(AIJob $job, string $reason): void
    {
        if ($job->charged_credits > 0) {
            $this->ledger->credit($job->tenant ?? tenant(), $job->charged_credits, 'refund', 'job:'.$job->id.':refund',
                __('ai_studio.wallet.refund', ['task' => $job->task]), 'ai_job', $job->id);
        }
        $job->update(['status' => 'refunded', 'charged_credits' => 0, 'error' => mb_substr($reason, 0, 500), 'finished_at' => now()]);
        $this->broadcast($job);
    }

    protected function perform(AIJob $job, AbstractDriver $driver): mixed
    {
        $photo = $this->vault->get(data_get($job->input_refs, 'photo'));
        $mime = data_get($job->input_refs, 'mime', 'image/jpeg');

        return match ($job->task) {
            'consultant' => $this->consult($job, $driver, (string) $photo, $mime),
            'marketing_copy' => ['en' => $driver->writeCaption((string) $job->prompt, data_get($job->input_refs, 'tone', 'luxury'), 'en'),
                'ar' => $driver->writeCaption((string) $job->prompt, data_get($job->input_refs, 'tone', 'luxury'), 'ar')],
            'marketing_banner' => $driver->generateImage(new ImageRequest((string) $job->prompt, null, aspect: data_get($job->input_refs, 'aspect', '1:1'))),
            default => $driver->editImage(new ImageRequest((string) $job->prompt, $photo, $mime, reference: $this->reference($job))),
        };
    }

    protected function consult(AIJob $job, AbstractDriver $driver, string $photo, string $mime): array
    {
        $analysis = $driver->analyzeFace($photo, $mime, $job->locale);
        $styles = Style::active()->get()->map(fn (Style $s) => ['id' => $s->id, 'name' => $s->getTranslation('name', 'en'), 'category' => $s->category, 'option' => $s->option])->values()->all();
        $looks = $styles ? $driver->recommend($analysis, $styles, $job->locale) : [];

        return ['analysis' => $analysis, 'looks' => $looks];
    }

    protected function reference(AIJob $job): ?string
    {
        $path = $job->style?->reference_path;

        return $path && Storage::disk('public')->exists($path) ? Storage::disk('public')->get($path) : null;
    }

    protected function persist(AIJob $job, RouteResult $route): array
    {
        $result = $route->result;

        if ($result instanceof ImageResult) {
            if ($reason = $this->moderation->check((string) $result->image, 'output')) {
                throw new \RuntimeException($reason);
            }
            if ($job->task === 'marketing_banner') {
                $path = 'ai-studio/marketing/'.$job->tenant_id.'/'.Str::ulid().'.'.(str_contains($result->mime, 'jpeg') ? 'jpg' : 'png');
                Storage::disk('public')->put($path, $result->image);

                return ['image' => $path, 'public' => true];
            }
            $path = $this->vault->put($job->tenant_id, (string) $result->image, 'after');
            ClientLook::where('ai_job_id', $job->id)->update(['after_path' => $path]);

            return ['photo' => $path, 'mime' => $result->mime];
        }

        return is_array($result) ? $result : ['text' => (string) $result];
    }

    protected function broadcast(AIJob $job): void
    {
        try {
            AIJobUpdated::dispatch($job->tenant_id, $job->id, $job->status);
        } catch (Throwable) {
            // Broadcasting is best-effort; the UI polls the status endpoint anyway.
        }
    }
}
