<?php

namespace App\Filament\Dashboard\Resources\Stock;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Models\BranchStock;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\Stock\Pages\ManageBranchStock;
use App\Support\Exceptions\DomainException;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Stock levels per branch (branch_stock). Quantities change only through the ledger (Adjust stock). */
class BranchStockResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = BranchStock::class;

    protected static string $viewPermission = 'inventory.view';

    protected static string $managePermission = 'inventory.manage';

    protected static string $navGroup = 'inventory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'stock';

    public static function getModelLabel(): string
    {
        return __('workspace.inventory.stock_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.inventory.stock_plural');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) BranchStock::query()->low()->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('workspace.inventory.low_badge');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function variantOptions(): array
    {
        return ProductVariant::query()->with('product')->get()->filter(fn ($v) => $v->product)
            ->mapWithKeys(fn ($v) => [$v->id => $v->displayName().' · '.$v->sku])->all();
    }

    public static function branchOptions(): array
    {
        return Branch::query()->orderBy('sort')->get()->mapWithKeys(fn ($b) => [$b->id => $b->name])->all();
    }

    public static function adjustFields(bool $withTarget): array
    {
        return array_values(array_filter([
            $withTarget ? Select::make('branch_id')->label(__('salon.ui.branch'))->options(fn () => static::branchOptions())->required()->native(false)
                ->default(fn () => app(TenantContext::class)->branchId()) : null,
            $withTarget ? Select::make('product_variant_id')->label(__('workspace.products.label'))->options(fn () => static::variantOptions())->searchable()->required() : null,
            ToggleButtons::make('mode')->label(__('workspace.inventory.adjust_mode'))->inline()->default('delta')->live()->required()
                ->options(['delta' => __('workspace.inventory.mode_delta'), 'set' => __('workspace.inventory.mode_set')]),
            TextInput::make('qty')->label(fn (Get $get) => $get('mode') === 'set' ? __('workspace.inventory.counted_qty') : __('workspace.inventory.qty_change'))
                ->helperText(fn (Get $get) => $get('mode') === 'set' ? __('workspace.inventory.counted_help') : __('workspace.inventory.delta_help'))
                ->integer()->required()->notIn(fn (Get $get) => $get('mode') === 'delta' ? [0] : []),
            TextInput::make('note')->label(__('fields.reason'))->placeholder(__('workspace.inventory.reason_placeholder'))->maxLength(200)->required(),
        ]));
    }

    public static function runAdjustment(int $branchId, int $variantId, array $data): void
    {
        try {
            $movement = app(AdjustStock::class)->handle($branchId, $variantId, (int) $data['qty'], $data['mode'] ?? 'delta', $data['note'] ?? null);
            Notification::make()->success()->title($movement
                ? __('workspace.inventory.adjusted', ['balance' => $movement->balance_after])
                : __('workspace.inventory.no_change'))->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['variant.product', 'branch']))
            ->defaultSort('qty')
            ->columns([
                TextColumn::make('variant.product.name')->label(__('workspace.products.label'))->weight('semibold')
                    ->description(fn (BranchStock $r) => trim(($r->variant?->getTranslation('name', app()->getLocale(), false) ?: '').' · '.$r->variant?->sku, ' ·'))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('variant', fn ($v) => $v->where('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', $search)->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")))),
                TextColumn::make('branch.name')->label(__('salon.ui.branch'))->badge()->color('gray'),
                TextColumn::make('qty')->label(__('workspace.inventory.on_hand'))->alignCenter()->sortable()->badge()
                    ->color(fn (BranchStock $r) => $r->qty <= 0 ? 'danger' : ($r->isLow() ? 'warning' : 'success'))
                    ->icon(fn (BranchStock $r) => $r->isLow() ? 'heroicon-m-exclamation-triangle' : null),
                TextColumn::make('reserved')->label(__('workspace.inventory.reserved'))->alignCenter()->toggleable(),
                TextColumn::make('available')->label(__('workspace.inventory.available'))->state(fn (BranchStock $r) => $r->available())->alignCenter(),
                TextColumn::make('low_stock_threshold')->label(__('workspace.inventory.threshold'))->alignCenter()->toggleable(),
                TextColumn::make('value')->label(__('workspace.inventory.stock_value'))->alignEnd()->toggleable()
                    ->state(fn (BranchStock $r) => money(max(0, $r->qty) * (int) $r->variant?->cost)),
            ])
            ->filters([
                Filter::make('low')->label(__('workspace.inventory.low_only'))->toggle()->query(fn (Builder $query) => $query->low()),
                SelectFilter::make('branch_id')->label(__('salon.ui.branch'))->options(fn () => static::branchOptions()),
            ])
            ->recordActions([
                Action::make('adjust')->label(__('workspace.inventory.adjust'))->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->visible(fn () => static::canManage())
                    ->modalHeading(fn (BranchStock $record) => __('workspace.inventory.adjust_heading', ['item' => $record->variant?->displayName()]))
                    ->modalDescription(fn (BranchStock $record) => __('workspace.inventory.current', ['qty' => $record->qty, 'branch' => $record->branch?->name]))
                    ->schema(static::adjustFields(false))
                    ->action(fn (BranchStock $record, array $data) => static::runAdjustment($record->branch_id, $record->product_variant_id, $data)),
                Action::make('threshold')->label(__('workspace.inventory.threshold'))->icon(Heroicon::OutlinedBellAlert)->color('gray')
                    ->visible(fn () => static::canManage())
                    ->fillForm(fn (BranchStock $record) => ['low_stock_threshold' => $record->low_stock_threshold])
                    ->schema([TextInput::make('low_stock_threshold')->label(__('workspace.inventory.threshold'))->helperText(__('workspace.inventory.threshold_help'))->integer()->minValue(0)->required()])
                    // Only the alert threshold is edited here — never qty.
                    ->action(fn (BranchStock $record, array $data) => $record->update(['low_stock_threshold' => (int) $data['low_stock_threshold']])),
            ])
            ->emptyStateIcon(Heroicon::OutlinedArchiveBox)
            ->emptyStateHeading(__('workspace.inventory.stock_empty'))
            ->emptyStateDescription(__('workspace.inventory.stock_empty_body'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageBranchStock::route('/')];
    }
}
