<?php

namespace App\Filament\Concerns;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** Shared "quiet luxury" look & behaviour for the /admin, /dashboard and /pos panels. */
trait ConfiguresLuxePanel
{
    protected function luxe(Panel $panel): Panel
    {
        return $panel
            ->colors([
                'primary' => Color::hex('#C8A96A'),
                'gray' => Color::Stone,
                'success' => Color::hex('#4F7A5A'),
                'warning' => Color::hex('#C48A3A'),
                'danger' => Color::hex('#A4473F'),
                'info' => Color::hex('#6B7FA3'),
            ])
            ->font('Inter')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->darkMode(true)
            ->spa()
            ->databaseNotifications()
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => Blade::render('<x-luxe.panel-head />'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => Blade::render('<x-luxe.locale-switcher />'))
            ->middleware([
                \App\Http\Middleware\ResolveTenant::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                \App\Http\Middleware\SetLocale::class,
                \App\Http\Middleware\ApplyBranchScope::class,
                \App\Http\Middleware\SecurityHeaders::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
