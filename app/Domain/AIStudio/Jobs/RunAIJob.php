<?php

namespace App\Domain\AIStudio\Jobs;

use App\Domain\AIStudio\Actions\ProcessAIJob;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Async execution of an AI job inside its tenant context. Provider fallback happens inside one attempt. */
class RunAIJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $tenantId, public int $jobId)
    {
        $this->onQueue(config('ai.queue'));
    }

    public function handle(TenantContext $context, ProcessAIJob $process): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        $context->run($tenant, function () use ($process) {
            $job = AIJob::find($this->jobId);
            if ($job && ! $job->isFinished()) {
                $process->handle($job);
            }
        });
    }

    /** The worker crashed or timed out: never leave a client watching a spinner — fail (and refund) the look. */
    public function failed(?Throwable $e = null): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        app(TenantContext::class)->run($tenant, function () {
            $job = AIJob::find($this->jobId);
            if (! $job || $job->isFinished()) {
                return;
            }
            $message = __('ai_studio.errors.all_providers_failed');
            if ((int) $job->charged_credits > 0) {
                app(ProcessAIJob::class)->refund($job, $message);
            } else {
                $job->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
            }
        });
    }
}
