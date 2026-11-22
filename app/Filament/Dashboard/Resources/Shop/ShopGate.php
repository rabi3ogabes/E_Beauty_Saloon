<?php

namespace App\Filament\Dashboard\Resources\Shop;

/** Module + permission gate shared by every shop page/resource in the dashboard. */
final class ShopGate
{
    public static function view(): bool
    {
        return (bool) (tenant()?->hasModule('shop') && auth()->user()?->can('shop.view'));
    }

    public static function manage(): bool
    {
        return (bool) (tenant()?->hasModule('shop') && auth()->user()?->can('shop.manage'));
    }

    public static function group(): string
    {
        return __('salon.nav.shop');
    }
}
