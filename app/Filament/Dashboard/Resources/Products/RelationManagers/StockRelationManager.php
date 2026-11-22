<?php

namespace App\Filament\Dashboard\Resources\Products\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Read-only stock per branch for each variant of the product. Changes happen in Inventory (ledger). */
class StockRelationManager extends RelationManager
{
    protected static string $relationship = 'stockRows';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('workspace.inventory.stock_per_branch');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('inventory.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['variant', 'branch']))
            ->columns([
                TextColumn::make('branch.name')->label(__('salon.ui.branch'))->weight('semibold'),
                TextColumn::make('variant.sku')->label('SKU')->description(fn ($record) => $record->variant?->getTranslation('name', app()->getLocale(), false) ?: null),
                TextColumn::make('qty')->label(__('workspace.inventory.on_hand'))->alignCenter()->badge()
                    ->color(fn ($record) => $record->qty <= 0 ? 'danger' : ($record->isLow() ? 'warning' : 'success')),
                TextColumn::make('reserved')->label(__('workspace.inventory.reserved'))->alignCenter(),
                TextColumn::make('low_stock_threshold')->label(__('workspace.inventory.threshold'))->alignCenter(),
            ])
            ->emptyStateHeading(__('workspace.inventory.no_stock'))
            ->emptyStateDescription(__('workspace.inventory.no_stock_body'));
    }
}
