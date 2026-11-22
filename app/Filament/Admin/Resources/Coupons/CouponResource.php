<?php

namespace App\Filament\Admin\Resources\Coupons;

use App\Filament\Admin\Resources\Coupons\Pages\ManageCoupons;
use App\Filament\Support\Money;
use App\Filament\Support\Translatable;
use App\Domain\Billing\Models\Coupon;
use BackedEnum;
use Filament\Actions;
use Filament\Forms\Components;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns;
use Filament\Tables\Filters;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class CouponResource extends Resource
{
    protected static ?string $model = \App\Domain\Billing\Models\Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.billing');
    }

    public static function getModelLabel(): string
    {
        return __('admin.coupon.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.coupon.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.billing.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return true && static::canViewAny();
    }


    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Components\TextInput::make('code')->label(__('fields.code'))->required()->maxLength(40)->unique(ignoreRecord: true)
                ->extraInputAttributes(['style' => 'text-transform: uppercase']),
            Components\ToggleButtons::make('type')->label(__('fields.type'))->inline()->required()->default('percent')->live()
                ->options(['percent' => __('admin.coupon.percent'), 'fixed' => __('admin.coupon.fixed')]),
            Components\TextInput::make('value')->label(__('fields.value'))->numeric()->required()->minValue(0)
                ->suffix(fn ($get) => $get('type') === 'percent' ? '%' : config('salon.defaults.currency'))
                ->formatStateUsing(fn ($state) => $state === null ? null : $state / 100)
                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                ->helperText(__('admin.coupon.value_help')),
            Components\ToggleButtons::make('applies_to')->label(__('fields.applies_to'))->inline()->required()->default('first_cycle')
                ->options(['first_cycle' => __('admin.coupon.first_cycle'), 'forever' => __('admin.coupon.forever')]),
            Components\TextInput::make('max_uses')->label(__('fields.max_uses'))->numeric()->minValue(1),
            Components\DateTimePicker::make('expires_at')->label(__('fields.expires_at'))->native(false),
            Components\Toggle::make('is_active')->label(__('fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            Columns\TextColumn::make('code')->label(__('fields.code'))->weight('semibold')->copyable()->searchable(),
            Columns\TextColumn::make('value')->label(__('fields.value'))->state(fn ($record) => $record->displayValue()),
            Columns\TextColumn::make('applies_to')->label(__('fields.applies_to'))->badge()->color('gray')->formatStateUsing(fn ($state) => __('admin.coupon.'.$state)),
            Columns\TextColumn::make('used_count')->label(__('fields.used_count'))->formatStateUsing(fn ($state, $record) => $state.($record->max_uses ? ' / '.$record->max_uses : '')),
            Columns\TextColumn::make('expires_at')->label(__('fields.expires_at'))->date()->placeholder('—'),
            Columns\IconColumn::make('is_active')->label(__('fields.is_active'))->boolean(),
        ])->recordActions([Actions\EditAction::make(), Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCoupons::route('/')];
    }
}
