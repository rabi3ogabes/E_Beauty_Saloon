<?php

namespace App\Filament\Dashboard\Widgets\Insights;

use App\Domain\Insights\Actions\ApplyInsightAction;
use App\Domain\Insights\Models\InsightCard;
use App\Domain\Insights\Support\InsightsGate;
use App\Filament\Dashboard\Pages\Insights\InsightsHub;
use App\Support\Exceptions\DomainException;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

/** The three most important open insight cards on the command center, with their one-click actions. */
class InsightCardsWidget extends Widget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.dashboard.insights.cards-widget';

    public static function canView(): bool
    {
        return InsightsGate::reports();
    }

    protected function getViewData(): array
    {
        return [
            'cards' => InsightCard::query()->open()->orderByDesc('priority')->latest('id')->limit(3)->get(),
            'hubUrl' => InsightsHub::getUrl(),
        ];
    }

    public function act(int $id): void
    {
        try {
            $card = app(ApplyInsightAction::class)->handle(InsightCard::findOrFail($id));
            Notification::make()->success()->title(__('insights.cards.done.'.$card->actionType()))->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function dismiss(int $id): void
    {
        app(ApplyInsightAction::class)->dismiss(InsightCard::findOrFail($id));
    }
}
