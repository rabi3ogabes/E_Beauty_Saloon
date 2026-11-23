<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\ClientLook;
use App\Domain\AIStudio\Support\PhotoVault;

/**
 * Privacy retention: deletes photos of looks the client did not save once expired, and any other
 * job photos (consultations, orphans) older than the retention window. Runs daily (routes/schedules/ai_studio.php).
 */
class PurgeExpiredLooks
{
    public function __construct(protected PhotoVault $vault) {}

    public function handle(): int
    {
        $count = 0;
        ClientLook::withoutGlobalScopes()->where('saved', false)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->chunkById(200, function ($looks) use (&$count) {
                foreach ($looks as $look) {
                    $this->vault->delete($look->before_path, $look->after_path);
                    AIJob::withoutGlobalScopes()->whereKey($look->ai_job_id)->update(['input_refs' => null, 'output_refs' => null]);
                    $look->delete();
                    $count++;
                }
            });

        $cutoff = now()->subDays((int) config('ai.retention_days'));
        AIJob::withoutGlobalScopes()->whereNotNull('input_refs')->where('created_at', '<=', $cutoff)
            ->whereDoesntHave('look', fn ($q) => $q->withoutGlobalScopes()->where('saved', true))
            ->chunkById(200, function ($jobs) use (&$count) {
                foreach ($jobs as $job) {
                    $this->vault->delete(data_get($job->input_refs, 'photo'), data_get($job->output_refs, 'photo'));
                    $job->forceFill(['input_refs' => null, 'output_refs' => $job->task === 'consultant' ? $job->output_refs : null])->save();
                    $count++;
                }
            });

        return $count;
    }
}
