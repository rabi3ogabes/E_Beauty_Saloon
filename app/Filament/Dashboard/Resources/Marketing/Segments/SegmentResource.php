<?php

namespace App\Filament\Dashboard\Resources\Marketing\Segments;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Models\Tag;
use App\Domain\Marketing\Models\Segment;
use App\Domain\Marketing\Services\SegmentQuery;
use App\Filament\Dashboard\Resources\Marketing\Segments\Pages\ManageSegments;
use App\Filament\Marketing\GatedResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SegmentResource extends Resource
{
    use GatedResource;

    protected static ?string $model = Segment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 11;

    public static function getModelLabel(): string
    {
        return __('marketing.segments.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('marketing.segments.plural');
    }

    public static function fieldOptions(): array
    {
        return collect(SegmentQuery::FIELDS)->mapWithKeys(fn ($f) => [$f => __('marketing.segments.fields.'.$f)])->all();
    }

    /** Value input per condition type. */
    public static function valueField(): array
    {
        $is = fn (string ...$fields) => fn (Get $get) => in_array($get('field'), $fields, true);
        $locale = app()->getLocale();

        return [
            TextInput::make('value')->label(__('marketing.segments.value'))->numeric()->minValue(0)->live(debounce: 500)
                ->suffix(fn (Get $get) => in_array($get('field'), ['spent_over', 'spent_under'], true) ? (tenant()?->currency ?? 'QAR') : (in_array($get('field'), ['visited_within_days', 'not_visited_days'], true) ? __('marketing.common.days') : null))
                ->visible($is('visited_within_days', 'not_visited_days', 'spent_over', 'spent_under', 'visits_at_least'))->required(),
            Select::make('value')->label(__('marketing.segments.value'))->multiple()->searchable()->live()
                ->options(fn () => Service::orderBy('name->'.$locale)->get()->mapWithKeys(fn ($s) => [$s->id => $s->getTranslation('name', $locale)]))
                ->visible($is('bought_service'))->required(),
            Select::make('value')->label(__('marketing.segments.value'))->multiple()->searchable()->live()
                ->options(fn () => Product::limit(500)->get()->mapWithKeys(fn ($p) => [$p->id => $p->getTranslation('name', $locale)]))
                ->visible($is('bought_product'))->required(),
            Select::make('value')->label(__('marketing.segments.value'))->multiple()->live()
                ->options(fn () => Branch::all()->mapWithKeys(fn ($b) => [$b->id => $b->getTranslation('name', $locale)]))
                ->visible($is('branch'))->required(),
            Select::make('value')->label(__('marketing.segments.value'))->multiple()->live()->options(fn () => Tag::orderBy('name')->pluck('name', 'id'))
                ->visible($is('tags'))->required(),
            Select::make('value')->label(__('marketing.segments.value'))->multiple()->live()
                ->options(collect(['silver', 'gold', 'black'])->mapWithKeys(fn ($t) => [$t => __('marketing.loyalty.tiers.'.$t)]))
                ->visible($is('loyalty_tier'))->required(),
            Select::make('value')->label(__('marketing.segments.value'))->live()->options(__('marketing.segments.genders'))->visible($is('gender'))->required(),
            Select::make('value')->label(__('marketing.segments.value'))->live()->options(__('marketing.channels'))->visible($is('has_consent'))->required(),
            Select::make('value')->label(__('marketing.segments.value'))->multiple()->live()->options(__('marketing.segments.sources'))->visible($is('source'))->required(),
        ];
    }

    public static function liveCount(?array $rules): string
    {
        $base = app(SegmentQuery::class)->apply(Client::query(), ['match' => $rules['match'] ?? 'all', 'conditions' => array_values($rules['conditions'] ?? [])]);
        $count = (clone $base)->count();
        $byChannel = collect(['whatsapp', 'sms', 'email'])->map(fn ($c) => __('marketing.channels.'.$c).' '.number_format((clone $base)->withConsent($c)->count()))->join(' · ');

        return trans_choice('marketing.segments.clients', $count, ['count' => number_format($count)]).' — '.__('marketing.segments.reachable', ['channels' => $byChannel]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')->label(__('marketing.common.name'))->required()->maxLength(120),
            TextInput::make('description')->label(__('marketing.segments.description'))->maxLength(255),
            ToggleButtons::make('rules.match')->label(__('marketing.segments.match'))->inline()->default('all')->live()
                ->options(['all' => __('marketing.segments.match_all'), 'any' => __('marketing.segments.match_any')]),
            Repeater::make('rules.conditions')->label(__('marketing.segments.conditions'))->addActionLabel(__('marketing.segments.add_condition'))
                ->columns(2)->live()->defaultItems(1)->reorderable(false)
                ->schema([
                    Select::make('field')->label(__('marketing.segments.field'))->options(static::fieldOptions())->required()->live()
                        ->afterStateUpdated(fn (Set $set) => $set('value', null)),
                    ...static::valueField(),
                ]),
            Section::make(__('marketing.segments.live_count'))->icon('heroicon-o-users')->schema([
                Text::make(fn (Get $get) => static::liveCount(['match' => $get('rules.match'), 'conditions' => $get('rules.conditions') ?? []]))->weight('semibold'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            TextColumn::make('name')->label(__('marketing.common.name'))->weight('semibold')->searchable()->description(fn (Segment $r) => $r->description),
            TextColumn::make('rules')->label(__('marketing.segments.conditions'))->badge()->state(fn (Segment $r) => collect($r->rules['conditions'] ?? [])->pluck('field')->map(fn ($f) => __('marketing.segments.fields.'.$f))->all()),
            TextColumn::make('cached_count')->label(__('marketing.segments.count'))->numeric()->alignEnd()->description(fn (Segment $r) => $r->counted_at?->diffForHumans()),
            TextColumn::make('campaigns_count')->counts('campaigns')->label(__('marketing.campaigns.plural'))->alignEnd(),
        ])->recordActions([
            Action::make('refresh')->label(__('marketing.segments.refresh'))->icon('heroicon-o-arrow-path')->color('gray')->action(fn (Segment $record) => $record->refreshCount()),
            EditAction::make()->slideOver()->after(fn (Segment $record) => $record->refreshCount()),
            DeleteAction::make(),
        ])->emptyStateHeading(__('marketing.segments.empty'))->emptyStateDescription(__('marketing.segments.empty_help'))->emptyStateIcon('heroicon-o-user-group');
    }

    public static function getPages(): array
    {
        return ['index' => ManageSegments::route('/')];
    }
}
