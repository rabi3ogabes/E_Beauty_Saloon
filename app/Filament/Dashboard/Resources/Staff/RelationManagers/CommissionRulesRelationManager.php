<?php

namespace App\Filament\Dashboard\Resources\Staff\RelationManagers;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Service;
use App\Filament\Dashboard\Concerns\WorkspaceRelationManager;
use App\Filament\Support\Money;
use App\Support\Money\Money as MoneyValue;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Commission rules (commission_rules): percent (stored as basis points) or fixed (minor units),
 * per service / product / everything, with optional revenue tiers.
 */
class CommissionRulesRelationManager extends RelationManager
{
    use WorkspaceRelationManager;

    protected static string $relationship = 'commissionRules';

    protected static string $managePermission = 'staff.manage';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('workspace.commission.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(static::fields());
    }

    public static function fields(): array
    {
        return [
            Select::make('applies_to')->label(__('fields.applies_to'))->required()->native(false)->live()
                ->options(['all' => __('workspace.commission.applies.all'), 'service' => __('workspace.commission.applies.service'), 'product' => __('workspace.commission.applies.product')])
                ->default('service')->afterStateUpdated(fn (Set $set) => $set('target_id', null)),
            Select::make('target_id')->label(__('workspace.commission.target'))->placeholder(__('workspace.commission.any_item'))->searchable()->native(false)
                ->visible(fn (Get $get) => in_array($get('applies_to'), ['service', 'product'], true))
                ->options(fn (Get $get) => $get('applies_to') === 'product'
                    ? Product::query()->get()->pluck('name', 'id')
                    : Service::query()->get()->pluck('name', 'id')),
            ToggleButtons::make('type')->label(__('fields.type'))->inline()->required()->live()->default('percent')
                ->options(['percent' => __('workspace.commission.percent'), 'fixed' => __('workspace.commission.fixed')]),
            TextInput::make('value_input')->label(__('fields.value'))->numeric()->minValue(0)->required()
                ->suffix(fn (Get $get) => $get('type') === 'fixed' ? (tenant()?->currency ?? 'QAR') : '%')
                ->helperText(fn (Get $get) => $get('type') === 'fixed' ? __('workspace.commission.fixed_help') : __('workspace.commission.percent_help')),
            Repeater::make('tiers_input')->label(__('workspace.commission.tiers'))->helperText(__('workspace.commission.tiers_help'))
                ->columns(2)->columnSpanFull()->defaultItems(0)->reorderable(false)->addActionLabel(__('workspace.commission.add_tier'))
                ->schema([
                    Money::input('from')->label(__('workspace.commission.from_revenue'))->required(),
                    TextInput::make('value')->label(__('fields.value'))->numeric()->minValue(0)->required()
                        ->suffix(fn (Get $get) => $get('../../type') === 'fixed' ? (tenant()?->currency ?? 'QAR') : '%'),
                ]),
        ];
    }

    /** Form state -> stored columns (basis points / minor units). */
    public static function pack(array $data): array
    {
        $currency = tenant()?->currency ?? 'QAR';
        $toStored = fn ($v) => $data['type'] === 'fixed'
            ? MoneyValue::fromDecimal((string) ($v ?? 0), $currency)->amount
            : (int) round(((float) ($v ?? 0)) * 100);

        $data['value'] = $toStored($data['value_input'] ?? 0);
        $data['tiers'] = collect($data['tiers_input'] ?? [])->map(fn ($t) => ['from' => (int) ($t['from'] ?? 0), 'value' => $toStored($t['value'] ?? 0)])
            ->sortBy('from')->values()->all() ?: null;
        $data['target_type'] = in_array($data['applies_to'], ['service', 'product'], true) && filled($data['target_id'] ?? null)
            ? ($data['applies_to'] === 'product' ? (new Product)->getMorphClass() : (new Service)->getMorphClass()) : null;
        if (! $data['target_type']) {
            $data['target_id'] = null;
        }
        unset($data['value_input'], $data['tiers_input']);

        return $data;
    }

    public static function unpack(array $data): array
    {
        $currency = tenant()?->currency ?? 'QAR';
        $toInput = fn ($v) => ($data['type'] ?? 'percent') === 'fixed' ? MoneyValue::of((int) $v, $currency)->toDecimal() : ((int) $v) / 100;
        $data['value_input'] = $toInput($data['value'] ?? 0);
        $data['tiers_input'] = collect($data['tiers'] ?? [])->map(fn ($t) => ['from' => (int) ($t['from'] ?? 0), 'value' => $toInput($t['value'] ?? 0)])->all();

        return $data;
    }

    public static function describe(Model $rule): string
    {
        return $rule->type === 'fixed' ? money((int) $rule->value) : rtrim(rtrim(number_format($rule->value / 100, 2), '0'), '.').'%';
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('applies_to')->label(__('fields.applies_to'))->badge()->formatStateUsing(fn ($state) => __('workspace.commission.applies.'.$state)),
            TextColumn::make('target.name')->label(__('workspace.commission.target'))->placeholder(__('workspace.commission.any_item')),
            TextColumn::make('value')->label(__('fields.value'))->state(fn ($record) => static::describe($record))->weight('semibold'),
            TextColumn::make('tiers')->label(__('workspace.commission.tiers'))->state(fn ($record) => count($record->tiers ?? []) ?: '—')->alignCenter(),
        ])->headerActions([
            CreateAction::make()->label(__('workspace.commission.add'))
                ->mutateDataUsing(fn (array $data) => static::pack($data) + ['tenant_id' => $this->getOwnerRecord()->tenant_id]),
        ])->recordActions([
            EditAction::make()->mutateRecordDataUsing(fn (array $data) => static::unpack($data))->mutateDataUsing(fn (array $data) => static::pack($data)),
            DeleteAction::make(),
        ])->emptyStateHeading(__('workspace.commission.empty'))->emptyStateDescription(__('workspace.commission.empty_body'));
    }
}
