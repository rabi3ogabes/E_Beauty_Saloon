<?php

namespace App\Filament\Dashboard\Resources\StockMovements;

use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Inventory\Models\StockMovement;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\Stock\BranchStockResource;
use App\Filament\Dashboard\Resources\StockMovements\Pages\ListStockMovements;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Immutable stock movements ledger (read-only). */
class StockMovementResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = StockMovement::class;

    protected static string $viewPermission = 'inventory.view';

    protected static string $managePermission = 'inventory.manage';

    protected static string $navGroup = 'inventory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('workspace.inventory.movement_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.inventory.movement_plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function typeOptions(): array
    {
        return collect(StockLedger::TYPES)->mapWithKeys(fn ($t) => [$t => __('workspace.inventory.types.'.$t)])->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['variant.product', 'branch', 'user']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('workspace.common.date'))->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('variant.product.name')->label(__('workspace.products.label'))->weight('semibold')->description(fn ($record) => $record->variant?->sku),
                TextColumn::make('branch.name')->label(__('salon.ui.branch'))->badge()->color('gray'),
                TextColumn::make('type')->label(__('fields.type'))->badge()->formatStateUsing(fn ($state) => __('workspace.inventory.types.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'purchase', 'transfer_in', 'return' => 'success', 'sale', 'order', 'transfer_out', 'used_in_service' => 'info', default => 'warning'
                    }),
                TextColumn::make('qty')->label(__('workspace.inventory.qty'))->alignCenter()->formatStateUsing(fn ($state) => ($state > 0 ? '+' : '').$state)
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger')->weight('semibold'),
                TextColumn::make('balance_after')->label(__('workspace.inventory.balance'))->alignCenter(),
                TextColumn::make('note')->label(__('fields.notes'))->placeholder('—')->limit(40)->toggleable(),
                TextColumn::make('user.name')->label(__('workspace.common.by'))->placeholder(__('workspace.common.system'))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('fields.type'))->options(static::typeOptions())->multiple(),
                SelectFilter::make('branch_id')->label(__('salon.ui.branch'))->options(fn () => BranchStockResource::branchOptions()),
                Filter::make('period')->schema([
                    DatePicker::make('from')->label(__('workspace.common.from'))->native(false),
                    DatePicker::make('until')->label(__('workspace.common.to'))->native(false),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->emptyStateIcon(Heroicon::OutlinedArrowsRightLeft)
            ->emptyStateHeading(__('workspace.inventory.movements_empty'))
            ->emptyStateDescription(__('workspace.inventory.movements_empty_body'));
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }
}
