<?php

namespace App\Filament\Dashboard\Resources\Shop\ShopCoupons\Pages;

use App\Filament\Dashboard\Resources\Shop\ShopCoupons\ShopCouponResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageShopCoupons extends ManageRecords
{
    protected static string $resource = ShopCouponResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()];
    }
}
