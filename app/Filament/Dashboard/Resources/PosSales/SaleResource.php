<?php

namespace App\Filament\Dashboard\Resources\PosSales;

use App\Domain\POS\Models\Sale;
use App\Filament\Dashboard\Resources\PosSales\Pages\ListSales;
use App\Filament\Dashboard\Resources\PosSales\Pages\ViewSale;
use App\Filament\Pos\Support\SaleActions;
use App\Filament\Pos\Support\SaleTable;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/** POS sales for owners & managers (branch managers only see their branches via BranchScope). */
class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static ?string $slug = 'pos-sales';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return (bool) tenant()?->hasModule('pos') && ($user?->can('pos.refund') || $user?->can('reports.view'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.sales');
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
        return SaleTable::configure($table, dashboard: true)
            ->recordActions([ViewAction::make(), SaleActions::receipt(), SaleActions::refund(), SaleActions::void()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn (string $field) => TextEntry::make($field)->formatStateUsing(fn ($state, Sale $record) => money((int) $state, $record->currency));

        return $schema->components([
            Section::make()->columns(4)->columnSpanFull()->schema([
                TextEntry::make('number')->label(__('pos.sales.number'))->weight('semibold')->size('lg')->copyable(),
                TextEntry::make('status')->label(__('pos.sales.status'))->badge(),
                TextEntry::make('completed_at')->label(__('pos.sales.date'))->dateTime(),
                $money('total')->label(__('pos.sales.total'))->weight('semibold')->size('lg'),
                TextEntry::make('client.name')->label(__('pos.sales.client'))->placeholder('—'),
                TextEntry::make('branch.name')->label(__('pos.sales.branch')),
                TextEntry::make('register.name')->label(__('pos.sales.register'))->placeholder('—'),
                TextEntry::make('cashier.name')->label(__('pos.sales.cashier'))->placeholder('—'),
                $money('subtotal')->label(__('pos.ticket.subtotal')),
                $money('discount')->label(__('pos.ticket.discount')),
                $money('tax')->label(__('pos.shift.tax')),
                $money('tips')->label(__('pos.ticket.tips')),
                $money('refunded_total')->label(__('pos.refund.refunded')),
                TextEntry::make('is_offline')->label(__('pos.sales.offline'))->formatStateUsing(fn ($state) => $state ? __('salon.ui.yes') : __('salon.ui.no')),
                TextEntry::make('sync_conflicts')->label(__('pos.sales.conflicts'))->columnSpan(2)->placeholder('—')
                    ->formatStateUsing(fn ($state) => is_array($state) ? collect($state)->map(fn ($c) => $c['name'].' → '.$c['balance_after'])->implode(', ') : $state),
            ]),
            Section::make(__('pos.sales.items'))->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('name')->hiddenLabel()->columnSpan(2)->weight('semibold'),
                    TextEntry::make('staff.name')->hiddenLabel()->placeholder('—'),
                    TextEntry::make('qty')->hiddenLabel()->formatStateUsing(fn ($state, $record) => '× '.$state.($record->refunded_qty ? ' (−'.$record->refunded_qty.')' : '')),
                    TextEntry::make('total')->hiddenLabel()->alignEnd()->formatStateUsing(fn ($state, $record) => money((int) $state, $record->sale->currency)),
                ]),
            ]),
            Section::make(__('pos.sales.payments'))->columnSpanFull()->schema([
                RepeatableEntry::make('salePayments')->hiddenLabel()->columns(3)->schema([
                    TextEntry::make('method')->hiddenLabel()->badge()->formatStateUsing(fn ($state) => $state === 'deposit' ? __('pos.pay.deposit_paid') : __('salon.payments.methods.'.$state)),
                    TextEntry::make('reference')->hiddenLabel()->placeholder('—'),
                    TextEntry::make('amount')->hiddenLabel()->alignEnd()->formatStateUsing(fn ($state, $record) => money((int) $state, $record->sale->currency)),
                ]),
            ]),
            Section::make(__('pos.refund.history'))->columnSpanFull()->visible(fn (Sale $record) => $record->refunds()->exists())->schema([
                RepeatableEntry::make('refunds')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('created_at')->hiddenLabel()->dateTime(),
                    TextEntry::make('kind')->hiddenLabel()->badge()->formatStateUsing(fn ($state) => $state === 'void' ? __('pos.refund.void') : __('pos.refund.action')),
                    TextEntry::make('reason')->hiddenLabel(),
                    TextEntry::make('amount')->hiddenLabel()->alignEnd()->formatStateUsing(fn ($state) => money((int) $state)),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSales::route('/'),
            'view' => ViewSale::route('/{record}'),
        ];
    }
}
