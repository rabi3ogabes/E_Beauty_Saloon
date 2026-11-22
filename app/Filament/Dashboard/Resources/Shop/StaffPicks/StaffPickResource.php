<?php

namespace App\Filament\Dashboard\Resources\Shop\StaffPicks;

use App\Domain\Catalog\Models\Product;
use App\Domain\Shop\Models\StaffPick;
use App\Domain\Team\Models\Staff;
use App\Filament\Dashboard\Resources\Shop\ShopGate;
use App\Filament\Dashboard\Resources\Shop\StaffPicks\Pages\ManageStaffPicks;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** "Recommended by your stylist": products each team member recommends to her clients. */
class StaffPickResource extends Resource
{
    protected static ?string $model = StaffPick::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 7;

    public static function getNavigationGroup(): ?string
    {
        return ShopGate::group();
    }

    public static function getModelLabel(): string
    {
        return __('shop.admin.picks.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('shop.admin.picks.plural');
    }

    public static function canViewAny(): bool
    {
        return ShopGate::manage();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('staff_id')->label(__('shop.admin.fields.staff'))->options(fn () => Staff::withoutGlobalScopes()->where('tenant_id', tenant()?->id)->pluck('name', 'id'))->required()->searchable(),
            Select::make('product_id')->label(__('shop.admin.fields.product'))->options(fn () => Product::active()->pluck('name', 'id'))->required()->searchable(),
            TextInput::make('note')->label(__('shop.admin.fields.note'))->maxLength(255),
            TextInput::make('sort')->label(__('shop.admin.fields.sort'))->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->modifyQueryUsing(fn ($query) => $query->with(['staff', 'product']))->columns([
            TextColumn::make('staff.name')->label(__('shop.admin.fields.staff'))->weight('semibold'),
            TextColumn::make('product.name')->label(__('shop.admin.fields.product')),
            TextColumn::make('note')->label(__('shop.admin.fields.note'))->placeholder('—')->limit(40),
        ])
            ->filters([SelectFilter::make('staff_id')->label(__('shop.admin.fields.staff'))->options(fn () => Staff::withoutGlobalScopes()->where('tenant_id', tenant()?->id)->pluck('name', 'id'))])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageStaffPicks::route('/')];
    }
}
