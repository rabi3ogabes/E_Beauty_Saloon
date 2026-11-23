<?php

namespace App\Filament\Dashboard\Resources\Shop\Drivers;

use App\Domain\Branches\Models\Branch;
use App\Domain\Shop\Delivery\DeliveryPartners;
use App\Domain\Shop\Models\Driver;
use App\Domain\Team\Models\Staff;
use App\Filament\Dashboard\Resources\Shop\Drivers\Pages\ManageDrivers;
use App\Filament\Dashboard\Resources\Shop\ShopGate;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DriverResource extends Resource
{
    protected static ?string $model = Driver::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return ShopGate::group();
    }

    public static function getModelLabel(): string
    {
        return __('shop.admin.drivers.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('shop.admin.drivers.plural');
    }

    public static function canViewAny(): bool
    {
        return ShopGate::view();
    }

    public static function canCreate(): bool
    {
        return ShopGate::manage();
    }

    public static function canEdit($record): bool
    {
        return ShopGate::manage();
    }

    public static function canDelete($record): bool
    {
        return ShopGate::manage();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('shop.admin.fields.name'))->required()->maxLength(120),
            TextInput::make('phone')->label(__('shop.admin.fields.phone'))->tel()->maxLength(20),
            Select::make('partner')->label(__('shop.admin.fields.partner'))->options(fn () => app(DeliveryPartners::class)->options())->default('internal')->required(),
            Select::make('branch_id')->label(__('shop.admin.fields.branch'))->options(fn () => Branch::pluck('name', 'id')),
            Select::make('user_id')->label(__('shop.admin.fields.user'))->options(fn () => User::where('tenant_id', tenant()?->id)->pluck('name', 'id'))->searchable(),
            Select::make('staff_id')->label(__('shop.admin.fields.staff'))->options(fn () => Staff::pluck('name', 'id'))->searchable(),
            Toggle::make('is_active')->label(__('shop.admin.fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('shop.admin.fields.name'))->weight('semibold')->description(fn (Driver $record) => $record->phone),
            TextColumn::make('partner')->label(__('shop.admin.fields.partner'))->badge()->color('gray')
                ->formatStateUsing(fn ($state) => app(DeliveryPartners::class)->options()[$state] ?? $state),
            TextColumn::make('branch.name')->label(__('shop.admin.fields.branch'))->placeholder('—'),
            TextColumn::make('user.name')->label(__('shop.admin.fields.user'))->placeholder('—'),
            IconColumn::make('is_active')->label(__('shop.admin.fields.is_active'))->boolean(),
        ])
            ->recordActions([
                Action::make('link')->label(__('shop.admin.actions.copy_link'))->icon(Heroicon::OutlinedLink)->color('gray')
                    ->url(fn (Driver $record) => $record->signedUrl(), shouldOpenInNewTab: true)->visible(fn (Driver $record) => $record->is_active),
                EditAction::make()->slideOver(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('shop.admin.empty.drivers'))->emptyStateDescription(__('shop.admin.empty.drivers_sub'))->emptyStateIcon(Heroicon::OutlinedTruck);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDrivers::route('/')];
    }
}
