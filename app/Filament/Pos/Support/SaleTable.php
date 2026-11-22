<?php

namespace App\Filament\Pos\Support;

use App\Domain\POS\Enums\SaleStatus;
use App\Domain\POS\Models\Sale;
use App\Filament\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Sales table shared by /pos (cashier history) and /dashboard (owners & managers). */
class SaleTable
{
    public static function configure(Table $table, bool $dashboard = false): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', '!=', SaleStatus::Draft->value)->with(['client', 'salePayments', 'branch', 'cashier']))
            ->defaultSort('completed_at', 'desc')
            ->columns(array_values(array_filter([
                TextColumn::make('number')->label(__('pos.sales.number'))->searchable()->weight('semibold')->copyable(),
                TextColumn::make('completed_at')->label(__('pos.sales.date'))->dateTime('d M · H:i')->sortable(),
                TextColumn::make('client.name')->label(__('pos.sales.client'))->searchable()->placeholder('—'),
                $dashboard ? TextColumn::make('branch.name')->label(__('pos.sales.branch'))->toggleable() : null,
                $dashboard ? TextColumn::make('cashier.name')->label(__('pos.sales.cashier'))->toggleable() : null,
                TextColumn::make('methods')->label(__('pos.sales.payments'))->badge()->color('gray')
                    ->state(fn (Sale $record) => $record->salePayments->pluck('method')->unique()->map(fn ($m) => $m === 'deposit' ? __('pos.pay.deposit_paid') : __('salon.payments.methods.'.$m))->values()->all()),
                Money::column('total')->label(__('pos.sales.total')),
                TextColumn::make('status')->label(__('pos.sales.status'))->badge(),
                IconColumn::make('is_offline')->label(__('pos.sales.offline'))->boolean()->toggleable(isToggledHiddenByDefault: ! $dashboard),
            ])))
            ->filters([
                Filter::make('period')->schema([
                    Grid::make(2)->schema([
                        DatePicker::make('from')->label(__('pos.commissions.from'))->default($dashboard ? null : today()),
                        DatePicker::make('until')->label(__('pos.commissions.until')),
                    ]),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('completed_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('completed_at', '<=', $d))),
                SelectFilter::make('status')->label(__('pos.sales.status'))->options(collect(SaleStatus::cases())->reject(fn ($s) => $s === SaleStatus::Draft)->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])),
                TernaryFilter::make('is_offline')->label(__('pos.sales.offline')),
            ])
            ->emptyStateHeading(__('pos.sales.empty'))
            ->emptyStateIcon('heroicon-o-receipt-percent');
    }
}
