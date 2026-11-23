<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Models\AIConsent;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\ClientLook;
use App\Domain\AIStudio\Support\PhotoVault;
use App\Domain\CRM\Models\Client;
use Illuminate\Support\Facades\DB;

/** Data subject rights (GDPR / Qatar PDPPL style): export and erase a client's AI Studio data. */
class ClientAIData
{
    public function __construct(protected PhotoVault $vault) {}

    public function export(Client $client): array
    {
        return [
            'client' => ['name' => $client->name, 'phone' => $client->phone],
            'exported_at' => now()->toIso8601String(),
            'statement' => __('ai_studio.privacy.no_training'),
            'consents' => AIConsent::where('client_id', $client->id)->get(['version', 'age_confirmed', 'accepted_at', 'withdrawn_at'])->toArray(),
            'looks' => ClientLook::where('client_id', $client->id)->with('style')->get()->map(fn ($l) => [
                'reference' => $l->reference, 'category' => $l->category, 'style' => $l->style?->name, 'saved' => $l->saved,
                'created_at' => $l->created_at?->toIso8601String(), 'expires_at' => $l->expires_at?->toIso8601String(),
            ])->all(),
            'ai_requests' => AIJob::where('client_id', $client->id)->get()->map(fn ($j) => [
                'task' => $j->task, 'status' => $j->status, 'created_at' => $j->created_at?->toIso8601String(),
                'result' => $j->task === 'consultant' ? $j->output_refs : null,
            ])->all(),
        ];
    }

    public function erase(Client $client): int
    {
        return DB::transaction(function () use ($client) {
            $looks = ClientLook::where('client_id', $client->id)->get();
            foreach ($looks as $look) {
                $this->vault->delete($look->before_path, $look->after_path);
            }
            $deleted = ClientLook::where('client_id', $client->id)->delete();

            AIJob::where('client_id', $client->id)->get()->each(function (AIJob $job) {
                $this->vault->delete(data_get($job->input_refs, 'photo'), data_get($job->output_refs, 'photo'));
                // Keep the anonymized row for billing/margin integrity.
                $job->forceFill(['client_id' => null, 'input_refs' => null, 'output_refs' => null, 'prompt' => null, 'device_fingerprint' => null])->save();
            });
            RecordConsent::withdraw($client, null);
            activity()->performedOn($client)->log('ai.data.erased');

            return $deleted;
        });
    }
}
