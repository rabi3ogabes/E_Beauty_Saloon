<?php

namespace App\Providers\Filament;

use App\Filament\Concerns\ConfiguresLuxePanel;
use App\Http\Middleware\RequireCentralDomain;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;

/** Super admin panel — central domain only. */
class AdminPanelProvider extends PanelProvider
{
    use ConfiguresLuxePanel;

    public function panel(Panel $panel): Panel
    {
        return $this->luxe($panel)
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->profile(isSimple: false)
            ->brandName(config('salon.name').' · Admin')
            ->navigationGroups([
                NavigationGroup::make(fn () => __('salon.nav.salons')),
                NavigationGroup::make(fn () => __('salon.nav.billing')),
                NavigationGroup::make(fn () => __('salon.nav.growth')),
                NavigationGroup::make(fn () => __('salon.nav.ai')),
                NavigationGroup::make(fn () => __('salon.nav.website')),
                NavigationGroup::make(fn () => __('salon.nav.platform')),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->middleware([RequireCentralDomain::class], isPersistent: true);
    }
}
