<?php

namespace App\Filament\Dashboard\Resources\StockTransfers;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockTransfer;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\Stock\BranchStockResource;
use App\Filament\Dashboard\Resources\StockTransfers\Pages\ManageStockTransfers;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StockTransferResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = StockTransfer::class;

    protected static string $viewPermission = 'inventory.view';

    protected static string $managePermission = 'inventory.transfer';

    protected static string $navGroup = 'inventory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('workspace.transfers.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.transfers.plural');
    }

    /** Transfers are not a branch-owned model: restrict to the user's branches explicitly. */
    public static function getEloquentQuery(): Builder
    {
        $ctx = app(TenantContext::class);
        $ids = $ctx->branchId() ? [$ctx->branchId()] : $ctx->allowedBranchIds();

        return parent::getEloquentQuery()->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('from_branch_id', $ids ?: [0])->orWhereIn('to_branch_id', $ids ?: [0])));
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

    public static function formFields(): array
    {
        return [
            Select::make('from_branch_id')->label(__('workspace.transfers.from'))->options(fn () => BranchStockResource::branchOptions())->required()->native(false)->different('to_branch_id'),
            Select::make('to_branch_id')->label(__('workspace.transfers.to'))->required()->native(false)
                ->options(fn () => Branch::query()->withoutGlobalScope('allowed_branches')->orderBy('sort')->get()->mapWithKeys(fn ($b) => [$b->id => $b->name])),
            Repeater::make('lines')->label(__('workspace.transfers.lines'))->columns(3)->minItems(1)->defaultItems(1)->columnSpanFull()
                ->addActionLabel(__('workspace.purchase_orders.add_item'))
                ->schema([
                    Select::make('product_variant_id')->label(__('workspace.products.label'))->options(fn () => BranchStockResource::variantOptions())->searchable()->required()->distinct()->columnSpan(2),
                    TextInput::make('qty')->label(__('workspace.inventory.qty'))->integer()->minValue(1)->default(1)->required(),
                ]),
            Textarea::make('notes')->label(__('fields.notes'))->rows(2)->columnSpanFull(),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(static::formFields());
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('number')->label(__('fields.number'))->weight('semibold'),
            TextEntry::make('created_at')->label(__('workspace.common.date'))->dateTime(),
            TextEntry::make('fromBranch.name')->label(__('workspace.transfers.from')),
            TextEntry::make('toBranch.name')->label(__('workspace.transfers.to')),
            RepeatableEntry::make('items')->label(__('workspace.transfers.lines'))->columns(2)->columnSpanFull()->schema([
                TextEntry::make('variant')->label(__('workspace.products.label'))->state(fn ($record) => ProductVariant::with('product')->find($record->product_variant_id)?->displayName()),
                TextEntry::make('qty')->label(__('workspace.inventory.qty')),
            ]),
            TextEntry::make('notes')->label(__('fields.notes'))->placeholder('—')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['fromBranch', 'toBranch'])->withCount('items')->withSum('items as units', 'qty'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('fields.number'))->weight('semibold')->searchable(),
                TextColumn::make('fromBranch.name')->label(__('workspace.transfers.from'))->badge()->color('gray'),
                TextColumn::make('toBranch.name')->label(__('workspace.transfers.to'))->badge()->color('primary'),
                TextColumn::make('units')->label(__('workspace.transfers.units'))->alignCenter()
                    ->description(fn ($record) => trans_choice('workspace.transfers.lines_count', $record->items_count, ['count' => $record->items_count])),
                TextColumn::make('status')->label(__('fields.status'))->badge()->color('success')->formatStateUsing(fn ($state) => __('workspace.transfers.status.'.$state)),
                TextColumn::make('created_at')->label(__('workspace.common.date'))->dateTime('d M Y, H:i')->sortable(),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateIcon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->emptyStateHeading(__('workspace.transfers.empty'))
            ->emptyStateDescription(__('workspace.transfers.empty_body'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageStockTransfers::route('/')];
    }
}
