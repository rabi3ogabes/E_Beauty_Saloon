<?php

namespace App\Filament\Dashboard\Resources\Suppliers;

use App\Domain\Inventory\Models\PurchaseOrder;
use App\Domain\Inventory\Models\Supplier;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\Suppliers\Pages\ManageSuppliers;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = Supplier::class;

    protected static string $viewPermission = 'inventory.view';

    protected static string $managePermission = 'inventory.purchase';

    protected static string $navGroup = 'inventory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('workspace.suppliers.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.suppliers.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('fields.name'))->required()->maxLength(160),
            TextInput::make('contact_name')->label(__('workspace.suppliers.contact'))->maxLength(120),
            TextInput::make('phone')->label(__('fields.phone'))->tel()->maxLength(20),
            TextInput::make('email')->label(__('fields.email'))->email(),
            Textarea::make('address')->label(__('fields.address'))->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->searchable()->sortable()->description(fn ($record) => $record->contact_name),
            TextColumn::make('phone')->label(__('fields.phone'))->placeholder('—')->copyable(),
            TextColumn::make('email')->label(__('fields.email'))->placeholder('—')->copyable(),
            TextColumn::make('orders')->label(__('workspace.purchase_orders.plural'))->alignCenter()
                ->state(fn ($record) => PurchaseOrder::where('supplier_id', $record->id)->count()),
        ])->recordActions([EditAction::make()->slideOver(), DeleteAction::make()])
            ->emptyStateIcon(Heroicon::OutlinedTruck)
            ->emptyStateHeading(__('workspace.suppliers.empty'))
            ->emptyStateDescription(__('workspace.suppliers.empty_body'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageSuppliers::route('/')];
    }
}
