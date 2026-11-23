<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Concerns\ConfiguresLuxePanel;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureTenantIsAccessible;
use App\Http\Middleware\RequireTenantDomain;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

/** Cashier / reception POS — tablet-first, top navigation, dark theme friendly. */
class PosPanelProvider extends PanelProvider
{
    use ConfiguresLuxePanel;

    public function panel(Panel $panel): Panel
    {
        return $this->luxe($panel)
            ->id('pos')
            ->path('pos')
            ->login()
            ->profile(EditProfile::class, isSimple: false)
            ->brandName(fn () => (tenant()?->name ?? config('salon.name')).' · POS')
            ->topNavigation()
            ->renderHook(PanelsRenderHook::BODY_START, fn () => Blade::render('<x-luxe.impersonation-banner />'))
            ->discoverResources(in: app_path('Filament/Pos/Resources'), for: 'App\Filament\Pos\Resources')
            ->discoverPages(in: app_path('Filament/Pos/Pages'), for: 'App\Filament\Pos\Pages')
            ->discoverWidgets(in: app_path('Filament/Pos/Widgets'), for: 'App\Filament\Pos\Widgets')
            ->middleware([RequireTenantDomain::class, EnsureTenantIsAccessible::class.':dashboard', EnsureModuleEnabled::class.':pos'], isPersistent: true);
    }
}
