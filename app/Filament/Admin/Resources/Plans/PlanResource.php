<?php

namespace App\Filament\Admin\Resources\Plans;

use App\Domain\Billing\Models\Module;
use App\Domain\Billing\Models\Plan;
use App\Filament\Admin\Resources\Plans\Pages\ManagePlans;
use App\Filament\Support\Money;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.billing');
    }

    public static function getModelLabel(): string
    {
        return __('admin.plan.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.plan.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.plans.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make(__('admin.plan.identity'))->columnSpan(2)->columns(2)->schema([
                Translatable::make('name', __('fields.name')),
                Translatable::make('tagline', __('fields.tagline'), required: false),
                TextInput::make('slug')->label(__('fields.slug'))->required()->alphaDash()->unique(ignoreRecord: true)
                    ->default(fn () => Str::lower(Str::random(6))),
                TextInput::make('sort')->label(__('fields.sort'))->numeric()->default(0),
            ]),
            Section::make(__('admin.plan.visibility'))->columnSpan(1)->schema([
                Toggle::make('is_active')->label(__('fields.is_active'))->default(true),
                Toggle::make('is_public')->label(__('fields.is_public'))->default(true),
                Toggle::make('is_featured')->label(__('fields.is_featured')),
                TextInput::make('trial_days')->label(__('fields.trial_days'))->numeric()->minValue(0)->default(14)->suffix(__('admin.days')),
            ]),
            Section::make(__('admin.plan.pricing'))->columnSpan(2)->columns(2)->schema([
                Money::input('monthly_price')->label(__('fields.monthly_price'))->required(),
                Money::input('extra_branch_price')->label(__('fields.extra_branch_price'))->required(),
                TextInput::make('included_branches')->label(__('fields.included_branches'))->numeric()->minValue(1)->required()->default(1),
                TextInput::make('included_staff')->label(__('fields.included_staff'))->numeric()->minValue(1)->required()->default(5),
                TextInput::make('included_ai_credits')->label(__('fields.included_ai_credits'))->numeric()->minValue(0)->default(0),
                Select::make('currency')->label(__('fields.currency'))->options(['QAR' => 'QAR', 'AED' => 'AED', 'SAR' => 'SAR', 'USD' => 'USD'])->default('QAR')->required(),
            ]),
            Section::make(__('fields.included_modules'))->columnSpan(1)->schema([
                CheckboxList::make('included_modules')->hiddenLabel()
                    ->options(fn () => Module::orderBy('sort')->get()->mapWithKeys(fn ($m) => [$m->key => $m->name])),
            ]),
            Section::make(__('admin.plan.cycles'))->description(__('admin.plan.cycles_help'))->columnSpanFull()->schema([
                Repeater::make('billingCycles')->relationship()->hiddenLabel()->columns(2)->defaultItems(0)->reorderable(false)
                    ->schema([
                        Select::make('months')->label(__('fields.cycle'))->options([1 => __('admin.cycle.1'), 3 => __('admin.cycle.3'), 6 => __('admin.cycle.6'), 12 => __('admin.cycle.12')])->required()->distinct(),
                        TextInput::make('discount_percent')->label(__('salon.billing.discount'))->numeric()->minValue(0)->maxValue(90)->suffix('%')->required(),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort')->columns([
            TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->description(fn (Plan $r) => $r->tagline),
            Money::column('monthly_price')->label(__('fields.monthly_price')),
            TextColumn::make('included_branches')->label(__('fields.included_branches'))->alignCenter(),
            TextColumn::make('included_modules')->label(__('fields.included_modules'))->badge()->formatStateUsing(fn ($state) => __('salon.modules.'.$state))->wrap(),
            TextColumn::make('subscriptions_count')->counts('subscriptions')->label(__('admin.plan.subscribers'))->alignCenter(),
            IconColumn::make('is_featured')->label('★')->boolean(),
            IconColumn::make('is_public')->label(__('fields.is_public'))->boolean(),
        ])->recordActions([EditAction::make()->slideOver()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePlans::route('/')];
    }
}
