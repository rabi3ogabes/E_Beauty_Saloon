<?php

namespace App\Filament\Dashboard\Resources\Stock\Pages;

use App\Filament\Dashboard\Resources\Stock\BranchStockResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageBranchStock extends ManageRecords
{
    protected static string $resource = BranchStockResource::class;

    public function getSubheading(): ?string
    {
        return __('workspace.inventory.stock_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adjustStock')->label(__('workspace.inventory.adjust'))->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->visible(fn () => BranchStockResource::canManage())
                ->modalHeading(__('workspace.inventory.adjust'))
                ->schema(BranchStockResource::adjustFields(true))
                ->action(fn (array $data) => BranchStockResource::runAdjustment((int) $data['branch_id'], (int) $data['product_variant_id'], $data)),
        ];
    }
}
