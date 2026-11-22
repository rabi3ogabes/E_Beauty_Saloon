<?php

namespace App\Filament\Dashboard\Resources\Styles;

use App\Domain\AIStudio\Models\AITaskPrice;
use App\Domain\AIStudio\Models\Style;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Filament\Dashboard\Resources\Styles\Pages\ManageStyles;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Owner-curated style library: each look maps to a real service (+ optional shop shades). */
class StyleResource extends Resource
{
    protected static ?string $model = Style::class;

    protected static ?string $slug = 'ai-styles';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.ai_studio');
    }

    public static function getModelLabel(): string
    {
        return __('ai_studio.dashboard.style');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ai_studio.dashboard.styles');
    }

    public static function canViewAny(): bool
    {
        return (bool) (tenant()?->hasModule('ai_studio') && auth()->user()?->can('ai_studio.view'));
    }

    public static function canCreate(): bool
    {
        return static::canViewAny() && (bool) auth()->user()?->can('ai_studio.manage');
    }

    public static function canEdit($record): bool
    {
        return static::canCreate();
    }

    public static function canDelete($record): bool
    {
        return static::canCreate();
    }

    public static function categoryOptions(): array
    {
        return collect(Style::CATEGORIES)->keys()->mapWithKeys(fn ($c) => [$c => __('ai_studio.categories.'.$c)])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make()->columnSpan(2)->columns(2)->schema([
                Select::make('category')->label(__('ai_studio.dashboard.category'))->options(static::categoryOptions())->required()->live()
                    ->afterStateUpdated(fn ($state, $set) => $set('task', Style::CATEGORIES[$state]['task'] ?? 'hair_tryon')),
                Select::make('option')->label(__('ai_studio.dashboard.option'))
                    ->options(fn ($get) => collect(Style::CATEGORIES[$get('category')]['options'] ?? [])->mapWithKeys(fn ($o) => [$o => __('ai_studio.options.'.$o)])->all()),
                Translatable::make('name', __('fields.name')),
                Translatable::make('description', __('fields.description'), required: false, textarea: true, rows: 2),
                Textarea::make('prompt')->label(__('ai_studio.dashboard.prompt'))->helperText(__('ai_studio.dashboard.prompt_help'))->required()->rows(3)->columnSpanFull(),
            ]),
            Section::make()->columnSpan(1)->schema([
                FileUpload::make('reference_path')->label(__('ai_studio.dashboard.reference'))->image()->disk('public')->directory('ai-styles')->imageEditor()->maxSize(4096),
                ColorPicker::make('swatch')->label(__('ai_studio.dashboard.swatch')),
                Select::make('service_id')->label(__('ai_studio.dashboard.service'))->searchable()->preload()
                    ->options(fn () => Service::query()->active()->orderBy('sort')->get()->mapWithKeys(fn ($s) => [$s->id => $s->name])->all()),
                Select::make('product_variant_ids')->label(__('ai_studio.dashboard.shades'))->multiple()->searchable()
                    ->visible(fn () => tenant()?->hasModule('shop'))
                    ->options(fn () => ProductVariant::query()->with('product')->whereNotNull('swatch')->limit(200)->get()->mapWithKeys(fn ($v) => [$v->id => $v->displayName()])->all()),
                Select::make('task')->label(__('ai_studio.dashboard.task'))->required()->default('hair_tryon')
                    ->options(fn () => collect(AITaskPrice::DEFAULTS)->filter(fn ($d, $k) => $d[2] === 'image' && ! str_starts_with($k, 'marketing'))
                        ->mapWithKeys(fn ($d, $k) => [$k => AITaskPrice::for($k)->name.' · '.AITaskPrice::for($k)->credits.' cr'])->all()),
                Toggle::make('is_active')->label(__('salon.ui.status'))->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort')->columns([
            ImageColumn::make('reference_path')->disk('public')->label('')->square()->size(48),
            TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->searchable()
                ->description(fn ($record) => $record->option ? __('ai_studio.options.'.$record->option) : null),
            TextColumn::make('category')->label(__('ai_studio.dashboard.category'))->badge()->formatStateUsing(fn ($state) => __('ai_studio.categories.'.$state)),
            ColorColumn::make('swatch')->label(''),
            TextColumn::make('service.name')->label(__('ai_studio.dashboard.service'))->placeholder('—'),
            ToggleColumn::make('is_active')->label(__('salon.ui.status'))->disabled(fn () => ! static::canCreate()),
        ])->filters([
            SelectFilter::make('category')->label(__('ai_studio.dashboard.category'))->options(static::categoryOptions()),
        ])->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading(__('ai_studio.dashboard.empty_styles'))
            ->emptyStateDescription(__('ai_studio.dashboard.empty_styles_body'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageStyles::route('/')];
    }
}
