<?php

namespace App\Filament\Admin\Pages;

use App\Domain\AIStudio\Actions\AIMarginReport as MarginReport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Super admin: credits revenue vs provider cost, per provider. */
class AIMarginReport extends Page
{
    protected static ?string $slug = 'ai-margin';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.admin.ai-studio.margin';

    public string $period = '30';

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.ai');
    }

    public static function getNavigationLabel(): string
    {
        return __('ai_studio.admin.margin');
    }

    public function getTitle(): string
    {
        return __('ai_studio.admin.margin');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('platform.billing.view') ?? false;
    }

    public function getRows(): array
    {
        return app(MarginReport::class)->handle($this->period === 'all' ? null : now()->subDays((int) $this->period));
    }
}
