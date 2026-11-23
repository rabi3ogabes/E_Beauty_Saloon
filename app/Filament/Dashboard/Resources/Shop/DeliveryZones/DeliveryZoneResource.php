<?php

namespace App\Filament\Dashboard\Resources\Shop\DeliveryZones;

use App\Domain\Branches\Models\Branch;
use App\Domain\Shop\Models\DeliveryZone;
use App\Filament\Dashboard\Resources\Shop\DeliveryZones\Pages\ManageDeliveryZones;
use App\Filament\Dashboard\Resources\Shop\ShopGate;
use App\Filament\Support\Money;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeliveryZoneResource extends Resource
{
    protected static ?string $model = DeliveryZone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return ShopGate::group();
    }

    public static function getModelLabel(): string
    {
        return __('shop.admin.zones.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('shop.admin.zones.plural');
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
            Translatable::make('name', __('shop.admin.fields.name')),
            Select::make('branch_id')->label(__('shop.admin.fields.branch'))->options(fn () => Branch::pluck('name', 'id'))->required(),
            TextInput::make('sort')->label(__('shop.admin.fields.sort'))->numeric()->default(0),
            TagsInput::make('areas')->label(__('shop.admin.fields.areas'))->helperText(__('shop.admin.fields.areas_help'))->columnSpanFull(),
            Money::input('fee')->label(__('shop.admin.fields.fee'))->required()->default(0),
            Money::input('min_order')->label(__('shop.admin.fields.min_order'))->default(0),
            Money::input('free_shipping_threshold')->label(__('shop.admin.fields.free_shipping_threshold')),
            TextInput::make('eta_hours')->label(__('shop.admin.fields.eta_hours'))->numeric()->minValue(1)->default(24),
            Section::make(__('shop.admin.fields.same_day_enabled'))->columns(3)->columnSpanFull()->schema([
                Toggle::make('same_day_enabled')->label(__('shop.admin.fields.same_day_enabled'))->live(),
                TimePicker::make('same_day_cutoff')->label(__('shop.admin.fields.same_day_cutoff'))->seconds(false)->visible(fn (Get $get) => $get('same_day_enabled'))
                    ->dehydrateStateUsing(fn ($state) => $state ? substr((string) $state, 0, 5) : null),
                Money::input('same_day_fee')->label(__('shop.admin.fields.same_day_fee'))->default(0)->visible(fn (Get $get) => $get('same_day_enabled')),
            ]),
            Textarea::make('polygon')->label(__('shop.admin.fields.polygon'))->helperText(__('shop.admin.fields.polygon_help'))->rows(2)->columnSpanFull()
                ->formatStateUsing(fn ($state) => $state ? json_encode($state) : null)
                ->dehydrateStateUsing(fn ($state) => $state ? (json_decode((string) $state, true) ?: null) : null)
                ->rule(fn () => function ($attribute, $value, $fail) {
                    if ($value && ! is_array(json_decode((string) $value, true))) {
                        $fail(__('shop.admin.fields.polygon_help'));
                    }
                }),
            Toggle::make('is_active')->label(__('shop.admin.fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort')->columns([
            TextColumn::make('name')->label(__('shop.admin.fields.name'))->weight('semibold')
                ->description(fn (DeliveryZone $record) => collect($record->areas)->take(4)->implode(', ')),
            TextColumn::make('branch.name')->label(__('shop.admin.fields.branch')),
            Money::column('fee')->label(__('shop.admin.fields.fee')),
            Money::column('min_order')->label(__('shop.admin.fields.min_order')),
            TextColumn::make('free_shipping_threshold')->label(__('shop.admin.fields.free_shipping_threshold'))->formatStateUsing(fn ($state) => $state ? money((int) $state) : '—')->alignEnd(),
            IconColumn::make('same_day_enabled')->label(__('shop.admin.fields.same_day_enabled'))->boolean(),
            IconColumn::make('is_active')->label(__('shop.admin.fields.is_active'))->boolean(),
        ])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()])
            ->emptyStateHeading(__('shop.admin.empty.zones'))->emptyStateDescription(__('shop.admin.empty.zones_sub'))->emptyStateIcon(Heroicon::OutlinedMap);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDeliveryZones::route('/')];
    }
}
