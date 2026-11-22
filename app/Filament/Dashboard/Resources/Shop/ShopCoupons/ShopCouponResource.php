<?php

namespace App\Filament\Dashboard\Resources\Shop\ShopCoupons;

use App\Domain\Shop\Models\ShopCoupon;
use App\Filament\Dashboard\Resources\Shop\ShopCoupons\Pages\ManageShopCoupons;
use App\Filament\Dashboard\Resources\Shop\ShopGate;
use App\Filament\Support\Money;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ShopCouponResource extends Resource
{
    protected static ?string $model = ShopCoupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): ?string
    {
        return ShopGate::group();
    }

    public static function getModelLabel(): string
    {
        return __('shop.admin.coupons.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('shop.admin.coupons.plural');
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
            TextInput::make('code')->label(__('shop.admin.fields.code'))->required()->maxLength(40)->alphaDash()
                ->default(fn () => Str::upper(Str::random(8)))->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', tenant()?->id)),
            Select::make('type')->label(__('shop.admin.fields.type'))->options(['percent' => __('shop.admin.fields.percent'), 'fixed' => __('shop.admin.fields.fixed')])->default('percent')->required()->live(),
            TextInput::make('value')->label(__('shop.admin.fields.value'))->numeric()->required()->minValue(0)
                ->suffix(fn (Get $get) => $get('type') === 'percent' ? '%' : (tenant()?->currency ?? 'QAR'))
                ->formatStateUsing(fn ($state) => $state === null ? null : $state / 100) // bp → % or minor → decimal
                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                ->helperText(fn (Get $get) => $get('type') === 'percent' ? '10 = 10%' : null),
            Money::input('min_order')->label(__('shop.admin.fields.min_order'))->default(0),
            Money::input('max_discount')->label(__('shop.admin.fields.max_discount')),
            TextInput::make('max_uses')->label(__('shop.admin.fields.max_uses'))->numeric()->minValue(1),
            TextInput::make('max_uses_per_client')->label(__('shop.admin.fields.max_uses_per_client'))->numeric()->minValue(1),
            DateTimePicker::make('starts_at')->label(__('shop.admin.fields.starts_at')),
            DateTimePicker::make('expires_at')->label(__('shop.admin.fields.expires_at'))->after('starts_at'),
            Toggle::make('free_shipping')->label(__('shop.admin.fields.free_shipping')),
            Toggle::make('is_active')->label(__('shop.admin.fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('code')->label(__('shop.admin.fields.code'))->weight('semibold')->fontFamily('mono')->copyable()->searchable(),
            TextColumn::make('value')->label(__('shop.admin.fields.value'))
                ->formatStateUsing(fn ($state, ShopCoupon $record) => $record->type === 'percent' ? rtrim(rtrim(number_format($state / 100, 2), '0'), '.').'%' : money((int) $state)),
            Money::column('min_order')->label(__('shop.admin.fields.min_order')),
            TextColumn::make('uses_count')->label(__('shop.admin.fields.uses'))->formatStateUsing(fn ($state, ShopCoupon $record) => $state.($record->max_uses ? ' / '.$record->max_uses : ''))->alignCenter(),
            TextColumn::make('expires_at')->label(__('shop.admin.fields.expires_at'))->date()->placeholder('—'),
            IconColumn::make('free_shipping')->label(__('shop.admin.fields.free_shipping'))->boolean(),
            IconColumn::make('is_active')->label(__('shop.admin.fields.is_active'))->boolean(),
        ])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()])
            ->emptyStateHeading(__('shop.admin.empty.coupons'))->emptyStateDescription(__('shop.admin.empty.coupons_sub'))->emptyStateIcon(Heroicon::OutlinedTicket);
    }

    public static function getPages(): array
    {
        return ['index' => ManageShopCoupons::route('/')];
    }
}
