<?php

namespace App\Providers\Filament;

use App\Filament\Concerns\ConfiguresLuxePanel;
use App\Http\Middleware\EnsureTenantIsAccessible;
use App\Http\Middleware\RequireTenantDomain;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

/** Salon owner & team workspace — tenant domains only. */
class DashboardPanelProvider extends PanelProvider
{
    use ConfiguresLuxePanel;

    public function panel(Panel $panel): Panel
    {
        return $this->luxe($panel)
            ->id('dashboard')
            ->path('dashboard')
            ->login()
            ->passwordReset()
            ->profile(isSimple: false)
            ->brandName(fn () => tenant()?->name ?? config('salon.name'))
            ->brandLogo(fn () => tenant()?->brand('logo_path') ? asset('storage/'.tenant()->brand('logo_path')) : null)
            ->brandLogoHeight('2.25rem')
            ->navigationGroups([
                NavigationGroup::make(fn () => __('salon.nav.today')),
                NavigationGroup::make(fn () => __('salon.nav.bookings')),
                NavigationGroup::make(fn () => __('salon.nav.sales')),
                NavigationGroup::make(fn () => __('salon.nav.clients')),
                NavigationGroup::make(fn () => __('salon.nav.catalog')),
                NavigationGroup::make(fn () => __('salon.nav.inventory')),
                NavigationGroup::make(fn () => __('salon.nav.shop')),
                NavigationGroup::make(fn () => __('salon.nav.marketing')),
                NavigationGroup::make(fn () => __('salon.nav.ai_studio')),
                NavigationGroup::make(fn () => __('salon.nav.website')),
                NavigationGroup::make(fn () => __('salon.nav.team')),
                NavigationGroup::make(fn () => __('salon.nav.reports')),
                NavigationGroup::make(fn () => __('salon.nav.settings'))->collapsed(),
            ])
            ->renderHook(PanelsRenderHook::BODY_START, fn () => Blade::render('<x-luxe.impersonation-banner /><x-luxe.read-only-banner />'))
            ->renderHook(PanelsRenderHook::TOPBAR_START, fn () => Blade::render('<livewire:branch-switcher />'))
            ->discoverResources(in: app_path('Filament/Dashboard/Resources'), for: 'App\Filament\Dashboard\Resources')
            ->discoverPages(in: app_path('Filament/Dashboard/Pages'), for: 'App\Filament\Dashboard\Pages')
            ->discoverWidgets(in: app_path('Filament/Dashboard/Widgets'), for: 'App\Filament\Dashboard\Widgets')
            ->middleware([RequireTenantDomain::class, EnsureTenantIsAccessible::class.':dashboard'], isPersistent: true);
    }
}
