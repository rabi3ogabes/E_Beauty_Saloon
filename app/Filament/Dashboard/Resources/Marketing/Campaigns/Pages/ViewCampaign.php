<?php

namespace App\Filament\Dashboard\Resources\Marketing\Campaigns\Pages;

use App\Domain\Marketing\Actions\DecideAbWinner;
use App\Domain\Marketing\Actions\LaunchCampaign;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Domain\Marketing\Services\CampaignReport;
use App\Filament\Dashboard\Resources\Marketing\Campaigns\CampaignResource;
use App\Filament\Marketing\MarketingAccess;
use App\Support\Exceptions\DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/** Campaign report: funnel, revenue attributed (7-day window), A/B results, recipients. */
class ViewCampaign extends ViewRecord
{
    protected static string $resource = CampaignResource::class;

    protected string $view = 'filament.marketing.campaign-report';

    public function getTitle(): string
    {
        return $this->record->name;
    }

    protected function getViewData(): array
    {
        /** @var Campaign $campaign */
        $campaign = $this->record;

        return [
            'campaign' => $campaign,
            'report' => app(CampaignReport::class)->for($campaign),
            'recent' => CampaignMessage::with('client')->where('campaign_id', $campaign->id)->latest('updated_at')->limit(25)->get(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('launch')->label(__('marketing.campaigns.launch'))->icon('heroicon-o-paper-airplane')->color('primary')
                ->visible(fn () => $this->record->isEditable() && MarketingAccess::send())
                ->requiresConfirmation()
                ->modalDescription(fn () => CampaignResource::estimateLine($this->record->segment_id, $this->record->channels ?? []))
                ->action(function () {
                    try {
                        app(LaunchCampaign::class)->handle($this->record);
                        Notification::make()->success()->title(__('marketing.campaigns.launched'))->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                    $this->record->refresh();
                }),
            Action::make('decide')->label(__('marketing.campaigns.decide_now'))->icon('heroicon-o-trophy')->color('gray')
                ->visible(fn () => $this->record->status === 'testing' && MarketingAccess::send())
                ->requiresConfirmation()
                ->action(function () {
                    app(DecideAbWinner::class)->handle($this->record, force: true);
                    $this->record->refresh();
                }),
            Action::make('cancel')->label(__('marketing.campaigns.cancel'))->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn () => in_array($this->record->status, ['draft', 'scheduled', 'testing', 'sending'], true) && MarketingAccess::send())
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update(['status' => 'cancelled']);
                    $this->record->messages()->whereIn('status', ['queued', 'held', 'skipped_quiet_hours'])->delete();
                    Notification::make()->success()->title(__('marketing.campaigns.cancelled'))->send();
                }),
        ];
    }
}
