<?php

namespace App\Filament\Dashboard\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

/**
 * Owner command center ("Today"). Widgets from every module are discovered from
 * app/Filament/Dashboard/Widgets and ordered by their $sort.
 */
class Dashboard extends BaseDashboard
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    public function mount(): void
    {
        // First login of the owner: finish the onboarding wizard before the command center.
        // Client-side redirect keeps the dashboard response itself a 200 (health checks, support sessions).
        if (tenant() && tenant()->onboarded_at === null && Onboarding::canAccess()) {
            $this->js('window.location.replace('.json_encode(Onboarding::getUrl(), JSON_UNESCAPED_SLASHES).')');
        }
    }

    public static function getNavigationLabel(): string
    {
        return __('salon.nav.today');
    }

    public function getTitle(): string
    {
        $hour = (int) now(tenant()?->timezone ?? config('app.timezone'))->format('G');
        $key = $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening');

        return __('dashboard.greeting.'.$key, ['name' => explode(' ', (string) auth()->user()?->name)[0]]);
    }

    public function getSubheading(): ?string
    {
        return now(tenant()?->timezone ?? config('app.timezone'))->translatedFormat('l, j F');
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }
}
