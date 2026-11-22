<?php

namespace App\Filament\Admin\Resources\Modules;

use App\Filament\Admin\Resources\Modules\Pages\ManageModules;
use App\Filament\Support\Money;
use App\Filament\Support\Translatable;
use App\Domain\Billing\Models\Module;
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

class ModuleResource extends Resource
{
    protected static ?string $model = \App\Domain\Billing\Models\Module::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.billing');
    }

    public static function getModelLabel(): string
    {
        return __('admin.module.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.module.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.plans.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false && static::canViewAny();
    }


    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Translatable::make('name', __('fields.name')),
            Translatable::make('description', __('fields.description'), required: false, textarea: true),
            Components\TextInput::make('key')->label(__('fields.key'))->disabled()->dehydrated(false),
            Components\Select::make('pricing_mode')->label(__('fields.pricing_mode'))->required()
                ->options(['per_account' => __('admin.module.per_account'), 'per_branch' => __('admin.module.per_branch')]),
            Components\TextInput::make('icon')->label(__('fields.icon')),
            Components\TextInput::make('sort')->label(__('fields.sort'))->numeric()->default(0),
            Components\Toggle::make('is_active')->label(__('fields.is_active')),
            Section::make(__('admin.module.prices'))->columnSpanFull()->schema([
                Components\Repeater::make('prices')->relationship()->hiddenLabel()->columns(3)->defaultItems(0)->schema([
                    Components\Select::make('plan_id')->label(__('fields.plan'))->relationship('plan', 'slug')->placeholder(__('admin.module.any_plan')),
                    Money::input('price_per_month')->label(__('fields.price_per_month'))->required(),
                    Components\Toggle::make('per_branch')->label(__('admin.module.per_branch'))->inline(false),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort')->columns([
            Columns\TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->description(fn ($record) => $record->key),
            Columns\TextColumn::make('pricing_mode')->label(__('fields.pricing_mode'))->badge()->color('gray')->formatStateUsing(fn ($state) => __('admin.module.'.$state)),
            Columns\TextColumn::make('prices.price_per_month')->label(__('fields.price_per_month'))->formatStateUsing(fn ($state) => money((int) $state))->listWithLineBreaks(),
            Columns\IconColumn::make('is_active')->label(__('fields.is_active'))->boolean(),
        ])->recordActions([Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageModules::route('/')];
    }
}
