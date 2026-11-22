<?php

namespace App\Filament\Dashboard\Resources\Marketing\Promotions;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Service;
use App\Domain\Marketing\Models\Promotion;
use App\Filament\Dashboard\Resources\Marketing\Promotions\Pages\ManagePromotions;
use App\Filament\Marketing\GatedResource;
use App\Filament\Support\Money;
use App\Filament\Support\Translatable;
use App\Support\Money\Money as MoneyValue;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PromotionResource extends Resource
{
    use GatedResource;

    protected static ?string $model = Promotion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 23;

    public static function getModelLabel(): string
    {
        return __('marketing.promotions.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('marketing.promotions.plural');
    }

    public static function state(Promotion $p): array
    {
        return match (true) {
            ! $p->is_active => [__('marketing.promotions.paused'), 'gray'],
            $p->starts_at?->isFuture() => [__('marketing.promotions.scheduled'), 'warning'],
            $p->ends_at?->isPast(), $p->usage_limit && $p->used_count >= $p->usage_limit => [__('marketing.promotions.ended'), 'danger'],
            default => [__('marketing.promotions.live'), 'success'],
        };
    }

    public static function form(Schema $schema): Schema
    {
        $locale = app()->getLocale();
        $currency = tenant()?->currency ?? 'QAR';
        $type = fn (string ...$types) => fn (Get $get) => in_array($get('type'), $types, true);

        return $schema->columns(2)->components([
            Translatable::make('name', __('marketing.common.name')),
            Select::make('type')->label(__('marketing.promotions.type'))->options(__('marketing.promotions.types'))->required()->live()->default('coupon'),
            TextInput::make('code')->label(__('marketing.promotions.code'))->maxLength(40)->alphaDash()->required($type('coupon'))->visible($type('coupon'))
                ->extraInputAttributes(['class' => 'uppercase']),
            ToggleButtons::make('discount_type')->label(__('marketing.promotions.discount_type'))->options(__('marketing.promotions.discount_types'))->inline()->default('percent')->live()->hidden($type('bogo')),
            TextInput::make('discount_value')->label(__('marketing.promotions.discount_value'))->numeric()->minValue(0)->required()->hidden($type('bogo'))
                ->suffix(fn (Get $get) => $get('discount_type') === 'fixed' ? $currency : '%')
                ->formatStateUsing(fn ($state, $record) => $state === null ? null : ($record?->discount_type === 'fixed' ? MoneyValue::of((int) $state, $currency)->toDecimal() : $state / 100))
                ->dehydrateStateUsing(fn ($state, Get $get) => $get('discount_type') === 'fixed' ? MoneyValue::fromDecimal((string) ($state ?: 0), $currency)->amount : (int) round(((float) $state) * 100)),

            Section::make(__('marketing.promotions.rules'))->columnSpanFull()->columns(3)->schema([
                Select::make('rules.applies_to')->label(__('marketing.promotions.applies_to'))->options(__('marketing.promotions.applies'))->default('all')->live(),
                Select::make('rules.service_ids')->label(__('marketing.promotions.services'))->multiple()->searchable()
                    ->options(fn () => Service::all()->mapWithKeys(fn ($s) => [$s->id => $s->getTranslation('name', $locale)]))
                    ->visible(fn (Get $get) => $get('rules.applies_to') !== 'products'),
                Select::make('rules.product_ids')->label(__('marketing.promotions.products'))->multiple()->searchable()
                    ->options(fn () => Product::limit(500)->get()->mapWithKeys(fn ($p) => [$p->id => $p->getTranslation('name', $locale)]))
                    ->visible(fn (Get $get) => $get('rules.applies_to') !== 'services'),
                Money::input('rules.min_subtotal')->label(__('marketing.promotions.min_subtotal')),
                CheckboxList::make('rules.weekdays')->label(__('marketing.promotions.weekdays'))->columns(7)->columnSpanFull()->visible($type('happy_hour'))
                    ->options(collect(__('marketing.promotions.weekday_names'))->mapWithKeys(fn ($d, $i) => [$i => $d])->all())->default([0, 1, 2, 3, 4]),
                TimePicker::make('rules.from')->label(__('marketing.promotions.from'))->seconds(false)->visible($type('happy_hour'))->default('10:00'),
                TimePicker::make('rules.to')->label(__('marketing.promotions.to'))->seconds(false)->visible($type('happy_hour'))->default('13:00'),
                TextInput::make('rules.days_before')->label(__('marketing.promotions.days_before'))->numeric()->default(7)->visible($type('birthday')),
                TextInput::make('rules.days_after')->label(__('marketing.promotions.days_after'))->numeric()->default(7)->visible($type('birthday')),
                TextInput::make('rules.buy_quantity')->label(__('marketing.promotions.buy_quantity'))->numeric()->minValue(1)->default(2)->visible($type('bogo')),
                TextInput::make('rules.get_quantity')->label(__('marketing.promotions.get_quantity'))->numeric()->minValue(1)->default(1)->visible($type('bogo')),
                TextInput::make('rules.get_discount_bp')->label(__('marketing.promotions.get_discount'))->numeric()->minValue(1)->maxValue(100)->suffix('%')->default(100)->visible($type('bogo'))
                    ->formatStateUsing(fn ($state) => $state ? $state / 100 : 100)->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
                Toggle::make('rules.exclusive')->label(__('marketing.promotions.exclusive'))->visible($type('coupon'))->inline(false),
            ]),

            Section::make(__('marketing.promotions.schedule'))->columnSpanFull()->schema([
                Grid::make(['default' => 1, 'md' => 4])->schema([
                    DateTimePicker::make('starts_at')->label(__('marketing.promotions.starts_at'))->seconds(false)->timezone(tenant()?->timezone ?? 'UTC'),
                    DateTimePicker::make('ends_at')->label(__('marketing.promotions.ends_at'))->seconds(false)->timezone(tenant()?->timezone ?? 'UTC')->after('starts_at')->required($type('flash_sale')),
                    TextInput::make('usage_limit')->label(__('marketing.promotions.usage_limit'))->numeric()->minValue(1),
                    TextInput::make('per_client_limit')->label(__('marketing.promotions.per_client_limit'))->numeric()->minValue(1),
                    Select::make('branch_ids')->label(__('marketing.common.branches'))->multiple()->placeholder(__('marketing.common.all_branches'))->columnSpan(2)
                        ->options(fn () => Branch::all()->mapWithKeys(fn ($b) => [$b->id => $b->getTranslation('name', $locale)])),
                    Toggle::make('show_countdown')->label(__('marketing.promotions.show_countdown'))->visible($type('flash_sale'))->inline(false),
                    Toggle::make('is_active')->label(__('marketing.common.active'))->default(true)->inline(false),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $locale = app()->getLocale();

        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('name')->label(__('marketing.common.name'))->weight('semibold')->formatStateUsing(fn (Promotion $record) => $record->getTranslation('name', $locale))
                ->description(fn (Promotion $r) => $r->code)->searchable(['code']),
            TextColumn::make('type')->label(__('marketing.promotions.type'))->badge()->color('gray')->formatStateUsing(fn ($state) => __('marketing.promotions.types.'.$state)),
            TextColumn::make('discount_value')->label(__('marketing.promotions.discount_value'))->formatStateUsing(fn (Promotion $record) => $record->type === 'bogo'
                ? $record->rule('buy_quantity', 1).' + '.$record->rule('get_quantity', 1) : $record->discountLabel()),
            TextColumn::make('used_count')->label(__('marketing.promotions.used'))->formatStateUsing(fn (Promotion $r) => $r->used_count.($r->usage_limit ? ' / '.$r->usage_limit : ''))->alignCenter(),
            TextColumn::make('ends_at')->label(__('marketing.promotions.ends_at'))->since()->placeholder('—'),
            TextColumn::make('state')->label(__('marketing.common.status'))->badge()->state(fn (Promotion $r) => static::state($r)[0])->color(fn (Promotion $r) => static::state($r)[1]),
        ])->filters([SelectFilter::make('type')->label(__('marketing.promotions.type'))->options(__('marketing.promotions.types'))])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()])
            ->emptyStateHeading(__('marketing.promotions.empty'))->emptyStateDescription(__('marketing.promotions.empty_help'))->emptyStateIcon('heroicon-o-receipt-percent');
    }

    public static function getPages(): array
    {
        return ['index' => ManagePromotions::route('/')];
    }
}
