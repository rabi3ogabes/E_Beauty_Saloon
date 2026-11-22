<?php

namespace App\Filament\Pos\Resources\Sales;

use App\Domain\POS\Models\Sale;
use App\Filament\Pos\Resources\Sales\Pages\ListSales;
use App\Filament\Pos\Support\SaleActions;
use App\Filament\Pos\Support\SaleTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/** Cashier's sales history: reprint receipts, refund (manager PIN if needed), void (manager PIN). */
class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static ?string $slug = 'sales';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return (bool) tenant()?->hasModule('pos') && (auth()->user()?->can('pos.access') ?? false);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('pos.nav.sales');
    }

    public static function getModelLabel(): string
    {
        return __('pos.sales.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pos.sales.plural');
    }

    public static function table(Table $table): Table
    {
        return SaleTable::configure($table)->recordActions([SaleActions::receipt(), SaleActions::refund(), SaleActions::void()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSales::route('/')];
    }
}
