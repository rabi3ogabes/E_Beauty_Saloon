<?php

namespace App\Filament\Dashboard\Resources\PurchaseOrders;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\PurchaseOrder;
use App\Domain\Inventory\Models\Supplier;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Dashboard\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Dashboard\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Dashboard\Resources\Stock\BranchStockResource;
use App\Filament\Support\Money;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = PurchaseOrder::class;

    protected static string $viewPermission = 'inventory.view';

    protected static string $managePermission = 'inventory.purchase';

    protected static string $navGroup = 'inventory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('workspace.purchase_orders.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.purchase_orders.plural');
    }

    public static function canEdit(Model $record): bool
    {
        return false; // POs are created then progressed with actions (ordered -> received)
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            'received' => 'success', 'partially_received' => 'warning', 'ordered' => 'info', 'cancelled' => 'danger', default => 'gray'
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make(__('workspace.purchase_orders.details'))->columnSpan(['default' => 3, 'lg' => 1])->schema([
                Select::make('branch_id')->label(__('workspace.purchase_orders.deliver_to'))->options(fn () => BranchStockResource::branchOptions())->required()->native(false)
                    ->default(fn () => app(TenantContext::class)->branchId() ?? array_key_first(BranchStockResource::branchOptions())),
                Select::make('supplier_id')->label(__('workspace.suppliers.label'))->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id'))->searchable()->native(false),
                DatePicker::make('expected_at')->label(__('workspace.purchase_orders.expected'))->native(false)->minDate(today()),
                Textarea::make('notes')->label(__('fields.notes'))->rows(3),
            ]),
            Section::make(__('workspace.purchase_orders.items'))->columnSpan(['default' => 3, 'lg' => 2])->schema([
                Repeater::make('items')->hiddenLabel()->columns(4)->minItems(1)->defaultItems(1)->addActionLabel(__('workspace.purchase_orders.add_item'))
                    ->live()
                    ->schema([
                        Select::make('product_variant_id')->label(__('workspace.products.label'))->options(fn () => BranchStockResource::variantOptions())
                            ->searchable()->required()->distinct()->columnSpan(2)->live()
                            ->afterStateUpdated(fn ($state, Set $set) => $set('unit_cost', ProductVariant::find($state)?->cost !== null ? \App\Support\Money\Money::of((int) ProductVariant::find($state)->cost, tenant()?->currency ?? 'QAR')->toDecimal() : null)),
                        TextInput::make('qty')->label(__('workspace.inventory.qty'))->integer()->minValue(1)->default(1)->required()->live(onBlur: true),
                        Money::input('unit_cost')->label(__('workspace.purchase_orders.unit_cost'))->required()->live(onBlur: true),
                    ]),
                Text::make(fn (Get $get) => __('workspace.purchase_orders.estimated_total', ['total' => money(collect($get('items') ?? [])->sum(
                    fn ($i) => (int) ($i['qty'] ?? 0) * (int) round(((float) ($i['unit_cost'] ?? 0)) * 100)
                ))]))->weight('semibold'),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make(__('workspace.purchase_orders.details'))->columnSpan(1)->schema([
                TextEntry::make('number')->label(__('fields.number'))->weight('semibold')->copyable(),
                TextEntry::make('status')->label(__('fields.status'))->badge()->formatStateUsing(fn ($state) => __('workspace.purchase_orders.status.'.$state))->color(fn ($state) => static::statusColor($state)),
                TextEntry::make('branch.name')->label(__('workspace.purchase_orders.deliver_to')),
                TextEntry::make('supplier.name')->label(__('workspace.suppliers.label'))->placeholder('—'),
                TextEntry::make('expected_at')->label(__('workspace.purchase_orders.expected'))->date()->placeholder('—'),
                TextEntry::make('received_at')->label(__('workspace.purchase_orders.received_at'))->dateTime()->placeholder('—'),
                TextEntry::make('total')->label(__('fields.total'))->formatStateUsing(fn ($state) => money((int) $state)),
                TextEntry::make('notes')->label(__('fields.notes'))->placeholder('—'),
            ]),
            Section::make(__('workspace.purchase_orders.items'))->columnSpan(2)->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('variant_name')->label(__('workspace.products.label'))->state(fn ($record) => ProductVariant::with('product')->find($record->product_variant_id)?->displayName())->columnSpan(2),
                    TextEntry::make('qty')->label(__('workspace.purchase_orders.received_of'))->state(fn ($record) => $record->received_qty.' / '.$record->qty),
                    TextEntry::make('unit_cost')->label(__('workspace.purchase_orders.unit_cost'))->formatStateUsing(fn ($state) => money((int) $state)),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'branch'])->withCount('items'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('fields.number'))->weight('semibold')->searchable()->description(fn ($r) => $r->supplier?->name),
                TextColumn::make('branch.name')->label(__('salon.ui.branch'))->badge()->color('gray'),
                TextColumn::make('items_count')->label(__('workspace.purchase_orders.items'))->alignCenter(),
                Money::column('total')->label(__('fields.total')),
                TextColumn::make('status')->label(__('fields.status'))->badge()->formatStateUsing(fn ($state) => __('workspace.purchase_orders.status.'.$state))->color(fn ($state) => static::statusColor($state)),
                TextColumn::make('expected_at')->label(__('workspace.purchase_orders.expected'))->date()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('fields.status'))->options(collect(['draft', 'ordered', 'partially_received', 'received', 'cancelled'])->mapWithKeys(fn ($s) => [$s => __('workspace.purchase_orders.status.'.$s)])),
                SelectFilter::make('supplier_id')->label(__('workspace.suppliers.label'))->options(fn () => Supplier::query()->pluck('name', 'id')),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList)
            ->emptyStateHeading(__('workspace.purchase_orders.empty'))
            ->emptyStateDescription(__('workspace.purchase_orders.empty_body'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'view' => ViewPurchaseOrder::route('/{record}'),
        ];
    }
}
