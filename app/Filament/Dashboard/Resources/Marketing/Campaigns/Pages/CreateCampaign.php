<?php

namespace App\Filament\Dashboard\Resources\Marketing\Campaigns\Pages;

use App\Domain\Marketing\Actions\LaunchCampaign;
use App\Domain\Marketing\Models\Campaign;
use App\Filament\Dashboard\Resources\Marketing\Campaigns\CampaignResource;
use App\Filament\Marketing\MarketingAccess;
use App\Support\Exceptions\DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateCampaign extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = CampaignResource::class;

    protected function getSteps(): array
    {
        return CampaignResource::wizardSteps();
    }

    protected function handleRecordCreation(array $data): Model
    {
        abort_if(tenant()->isReadOnly(), 423);
        $channels = array_values(array_unique(array_filter([$data['primary_channel'] ?? null, $data['fallback_channel'] ?? null])));
        $content = collect($data['content'] ?? [])->map(fn ($c) => array_filter($c ?? []))->filter()->all();

        $campaign = DB::transaction(function () use ($data, $channels, $content) {
            $campaign = Campaign::create([
                'name' => $data['name'], 'segment_id' => $data['segment_id'], 'channels' => $channels,
                'whatsapp_template_id' => in_array('whatsapp', $channels, true) ? ($data['whatsapp_template_id'] ?? null) : null,
                'content' => $content, 'banner_id' => $data['banner_id'] ?? null, 'media_url' => $data['media_url'] ?? null,
                'cta_url' => $data['cta_url'] ?? null, 'cta_label' => $data['cta_label'] ?? null,
                'ab_enabled' => (bool) ($data['ab_enabled'] ?? false), 'ab_test_percent' => (int) ($data['ab_test_percent'] ?? 20),
                'ab_metric' => $data['ab_metric'] ?? 'click', 'ab_wait_hours' => (int) ($data['ab_wait_hours'] ?? 4),
                'status' => ($data['when'] ?? 'now') === 'later' ? 'scheduled' : 'draft',
                'scheduled_at' => ($data['when'] ?? 'now') === 'later' && ! empty($data['scheduled_at']) ? Carbon::parse($data['scheduled_at'], tenant()->timezone)->utc() : null,
                'created_by' => auth()->id(),
            ]);
            if ($campaign->ab_enabled) {
                $campaign->variants()->create(['key' => 'A', 'content' => $content, 'whatsapp_template_id' => $campaign->whatsapp_template_id]);
                $b = collect($data['variant_b'] ?? [])->map(fn ($c) => array_filter($c ?? []))->filter()->all();
                $campaign->variants()->create(['key' => 'B', 'content' => array_replace_recursive($content, $b), 'whatsapp_template_id' => $campaign->whatsapp_template_id]);
            }

            return $campaign;
        });

        if ($campaign->status === 'draft' && MarketingAccess::send()) {
            try {
                app(LaunchCampaign::class)->handle($campaign);
                Notification::make()->success()->title(__('marketing.campaigns.launched'))->send();
            } catch (DomainException $e) {
                Notification::make()->danger()->title($e->getMessage())->persistent()->send();
            }
        } elseif ($campaign->status === 'scheduled') {
            Notification::make()->success()->title(__('marketing.campaigns.scheduled_ok'))->send();
        }

        return $campaign;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return CampaignResource::getUrl('view', ['record' => $this->record]);
    }
}
