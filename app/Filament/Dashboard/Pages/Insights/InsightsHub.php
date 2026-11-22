<?php

namespace App\Filament\Dashboard\Pages\Insights;

use App\Domain\Insights\Actions\ApplyInsightAction;
use App\Domain\Insights\Actions\GenerateInsights;
use App\Domain\Insights\Analytics\ChurnRisk;
use App\Domain\Insights\Analytics\DemandForecast;
use App\Domain\Insights\Analytics\PricingAdvisor;
use App\Domain\Insights\Analytics\ReorderAdvisor;
use App\Domain\Insights\Ask\AskYourData;
use App\Domain\Insights\Models\InsightCard;
use App\Domain\Insights\Reports\ReportService;
use App\Domain\Insights\Support\InsightsGate;
use App\Domain\Inventory\Actions\CreatePurchaseOrder;
use App\Filament\Dashboard\Resources\Marketing\Campaigns\CampaignResource;
use App\Filament\Dashboard\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * AI insights: weekly cards with one-click drafts, churn-risk list, demand forecast, stock reorder suggestions,
 * smart pricing hints (owner approves) and "Ask your data".
 */
class InsightsHub extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?string $slug = 'insights';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.dashboard.insights.hub';

    #[Url]
    public string $tab = 'cards';

    public string $question = '';

    public ?array $answer = null;

    public ?int $forecastBranchId = null;

    public static function canAccess(): bool
    {
        return InsightsGate::reports();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.today');
    }

    public static function getNavigationLabel(): string
    {
        return __('insights.hub.nav');
    }

    public function getTitle(): string
    {
        return __('insights.hub.title');
    }

    public function getSubheading(): ?string
    {
        return __('insights.hub.subtitle');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label(__('insights.hub.refresh'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn () => InsightsGate::settings())
                ->action(function () {
                    app(GenerateInsights::class)->handle(tenant(), force: true);
                    unset($this->cards);
                    Notification::make()->success()->title(__('insights.hub.refreshed'))->send();
                }),
        ];
    }

    #[Computed]
    public function cards(): Collection
    {
        return InsightCard::query()->where('status', '!=', 'dismissed')
            ->orderByRaw("case when status = 'new' then 0 else 1 end")->orderByDesc('priority')->latest('id')->limit(24)->get();
    }

    #[Computed]
    public function churn(): array
    {
        return app(ChurnRisk::class)->list(limit: 50);
    }

    #[Computed]
    public function forecast(): array
    {
        $branches = app(ReportService::class)->branches();
        $branch = $branches->firstWhere('id', $this->forecastBranchId) ?? $branches->first();

        return $branch ? ['branch' => $branch, 'branches' => $branches, 'days' => app(DemandForecast::class)->forBranch($branch, 14)] : ['branch' => null, 'branches' => $branches, 'days' => []];
    }

    #[Computed]
    public function reorder(): Collection
    {
        return collect(app(ReorderAdvisor::class)->suggestions())->groupBy('branch_id');
    }

    #[Computed]
    public function pricing(): array
    {
        return app(PricingAdvisor::class)->hints();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['cards', 'churn', 'forecast', 'reorder', 'pricing', 'ask'], true) ? $tab : 'cards';
    }

    public function updatedForecastBranchId(): void
    {
        unset($this->forecast);
    }

    public function act(int $id): void
    {
        $card = InsightCard::findOrFail($id);
        try {
            $card = app(ApplyInsightAction::class)->handle($card);
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        unset($this->cards);
        $n = Notification::make()->success()->title(__('insights.cards.done.'.$card->actionType()));
        if ($id = $card->result['campaign_id'] ?? null) {
            $n->actions([Action::make('open')->label(__('insights.cards.open_campaign'))->url(CampaignResource::getUrl('view', ['record' => $id]))]);
        } elseif ($ids = $card->result['purchase_order_ids'] ?? null) {
            $n->actions([Action::make('open')->label(__('insights.cards.open_po'))->url(PurchaseOrderResource::getUrl('view', ['record' => $ids[0]]))]);
        }
        $n->send();
    }

    public function dismiss(int $id): void
    {
        app(ApplyInsightAction::class)->dismiss(InsightCard::findOrFail($id));
        unset($this->cards);
    }

    public function winBackDraft(): void
    {
        abort_unless(tenant()->hasModule('marketing') && auth()->user()->can('marketing.manage'), 403);
        $campaign = app(ApplyInsightAction::class)->campaignDraft(['kind' => 'win_back', 'days' => 45]);
        Notification::make()->success()->title(__('insights.cards.done.campaign_draft'))
            ->actions([Action::make('open')->label(__('insights.cards.open_campaign'))->url(CampaignResource::getUrl('view', ['record' => $campaign->id]))])->send();
    }

    public function reorderDraft(int $branchId): void
    {
        abort_unless(auth()->user()->can('inventory.purchase'), 403);
        $items = collect(app(ReorderAdvisor::class)->suggestions($branchId))->map(fn ($r) => ['product_variant_id' => $r['variant_id'], 'qty' => $r['suggested_qty'], 'unit_cost' => $r['unit_cost']])->all();
        try {
            $po = app(CreatePurchaseOrder::class)->handle($branchId, null, $items, notes: __('insights.drafts.po_note'));
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title(__('insights.cards.done.purchase_order_draft'))
            ->actions([Action::make('open')->label(__('insights.cards.open_po'))->url(PurchaseOrderResource::getUrl('view', ['record' => $po->id]))])->send();
    }

    public function ask(): void
    {
        $this->validate(['question' => 'required|string|max:300']);
        $this->answer = app(AskYourData::class)->ask($this->question);
    }

    public function useExample(string $text): void
    {
        $this->question = $text;
        $this->ask();
    }
}
