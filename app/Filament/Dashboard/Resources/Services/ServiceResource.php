<?php

namespace App\Filament\Dashboard\Resources\Services;

use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\Branches\RelationManagers\ResourcesRelationManager;
use App\Filament\Dashboard\Resources\Services\Pages\CreateService;
use App\Filament\Dashboard\Resources\Services\Pages\EditService;
use App\Filament\Dashboard\Resources\Services\Pages\ListServices;
use App\Filament\Dashboard\Resources\Services\RelationManagers\BranchOverridesRelationManager;
use App\Filament\Support\Money;
use App\Filament\Support\Translatable;
use App\Support\Money\Money as MoneyValue;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

class ServiceResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = Service::class;

    protected static string $viewPermission = 'catalog.view';

    protected static string $managePermission = 'catalog.manage';

    protected static string $navGroup = 'catalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('workspace.services.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.services.plural');
    }

    public static function genderOptions(): array
    {
        return collect(['women', 'men', 'kids', 'unisex'])->mapWithKeys(fn ($g) => [$g => __('workspace.service_gender.'.$g)])->all();
    }

    public static function form(Schema $schema): Schema
    {
        $currency = tenant()?->currency ?? 'QAR';

        return $schema->columns(3)->components([
            Grid::make(1)->columnSpan(['default' => 3, 'lg' => 2])->schema([
                Section::make(__('workspace.services.details'))->description(__('workspace.services.details_help'))->columns(2)->schema([
                    Translatable::make('name', __('fields.name')),
                    Translatable::make('description', __('fields.description'), required: false, textarea: true),
                    Select::make('service_category_id')->label(__('workspace.service_categories.label'))->native(false)->searchable()
                        ->options(fn () => ServiceCategory::query()->orderBy('sort')->get()->pluck('name', 'id')),
                    Select::make('gender')->label(__('workspace.services.gender'))->options(static::genderOptions())->default('women')->required()->native(false),
                    TextInput::make('slug')->label(__('fields.slug'))->alphaDash()->maxLength(80)->helperText(__('workspace.services.slug_help'))
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', tenant()?->id)),
                    TextInput::make('rebook_after_days')->label(__('workspace.services.rebook'))->numeric()->minValue(1)->suffix(__('workspace.common.days'))
                        ->helperText(__('workspace.services.rebook_help')),
                ]),
                Section::make(__('workspace.services.time_price'))->description(__('workspace.services.time_price_help'))->columns(3)->schema([
                    TextInput::make('duration_minutes')->label(__('fields.duration'))->numeric()->minValue(5)->maxValue(720)->step(5)->default(60)->required()->suffix(__('salon.ui.minutes')),
                    TextInput::make('buffer_before')->label(__('workspace.services.buffer_before'))->numeric()->minValue(0)->default(0)->suffix(__('salon.ui.minutes')),
                    TextInput::make('buffer_after')->label(__('workspace.services.buffer_after'))->numeric()->minValue(0)->default(0)->suffix(__('salon.ui.minutes')),
                    Money::input('price')->label(__('fields.price'))->required(),
                    Money::input('sale_price')->label(__('fields.sale_price'))->helperText(__('workspace.services.sale_help'))->lte('price'),
                ]),
                Section::make(__('workspace.services.variants'))->description(__('workspace.services.variants_help'))->collapsible()->schema([
                    Repeater::make('variants')->relationship()->hiddenLabel()->columns(2)->defaultItems(0)->orderColumn('sort')
                        ->addActionLabel(__('workspace.services.add_variant'))
                        ->itemLabel(fn (array $state) => $state['name']['en'] ?? null)->collapsible()
                        ->schema([
                            Translatable::make('name', __('fields.name')),
                            TextInput::make('duration_minutes')->label(__('fields.duration'))->numeric()->minValue(5)->required()->suffix(__('salon.ui.minutes')),
                            Money::input('price')->label(__('fields.price'))->required(),
                        ]),
                ]),
                Section::make(__('workspace.services.addons'))->description(__('workspace.services.addons_help'))->collapsible()->schema([
                    Repeater::make('addons')->relationship()->hiddenLabel()->columns(2)->defaultItems(0)
                        ->addActionLabel(__('workspace.services.add_addon'))
                        ->itemLabel(fn (array $state) => $state['name']['en'] ?? null)->collapsible()
                        ->schema([
                            Translatable::make('name', __('fields.name')),
                            TextInput::make('duration_minutes')->label(__('workspace.services.extra_time'))->numeric()->minValue(0)->default(0)->suffix(__('salon.ui.minutes')),
                            Money::input('price')->label(__('fields.price'))->required(),
                        ]),
                ]),
            ]),
            Grid::make(1)->columnSpan(['default' => 3, 'lg' => 1])->schema([
                Section::make(__('workspace.services.booking'))->schema([
                    Toggle::make('is_active')->label(__('fields.is_active'))->default(true),
                    Toggle::make('online_bookable')->label(__('workspace.services.online_bookable'))->helperText(__('workspace.services.online_bookable_help'))->default(true),
                    ToggleButtons::make('deposit_type')->label(__('workspace.services.deposit'))->inline()->live()->default('none')
                        ->options(['none' => __('workspace.services.deposit_none'), 'percent' => __('workspace.services.deposit_percent'), 'fixed' => __('workspace.services.deposit_fixed')]),
                    TextInput::make('deposit_percent')->label(__('workspace.services.deposit_percent'))->numeric()->minValue(1)->maxValue(100)->suffix('%')
                        ->visible(fn (Get $get) => $get('deposit_type') === 'percent')->required(fn (Get $get) => $get('deposit_type') === 'percent'),
                    TextInput::make('deposit_fixed')->label(__('workspace.services.deposit_fixed'))->numeric()->minValue(0)->step('0.01')->prefix($currency)
                        ->visible(fn (Get $get) => $get('deposit_type') === 'fixed')->required(fn (Get $get) => $get('deposit_type') === 'fixed'),
                    CheckboxList::make('resource_types')->label(__('workspace.services.resources'))->helperText(__('workspace.services.resources_help'))
                        ->options(ResourcesRelationManager::typeOptions())->columns(1),
                ]),
                Section::make(__('workspace.services.media'))->schema([
                    FileUpload::make('image_path')->label(__('workspace.common.image'))->image()->imageEditor()->disk('public')->directory('catalog/services')->maxSize(4096),
                    TextInput::make('video_url')->label(__('workspace.services.video'))->url()->placeholder('https://youtu.be/…'),
                ]),
            ]),
        ]);
    }

    /** Form deposit inputs -> deposit_type + deposit_value (basis points or minor units). */
    public static function packDeposit(array $data): array
    {
        $currency = tenant()?->currency ?? 'QAR';
        $type = $data['deposit_type'] ?? 'none';
        $data['deposit_value'] = match ($type) {
            'percent' => (int) round(((float) ($data['deposit_percent'] ?? 0)) * 100),
            'fixed' => MoneyValue::fromDecimal((string) ($data['deposit_fixed'] ?? 0), $currency)->amount,
            default => 0,
        };
        $data['deposit_type'] = in_array($type, ['percent', 'fixed'], true) ? $type : null;
        unset($data['deposit_percent'], $data['deposit_fixed']);
        if (blank($data['slug'] ?? null)) {
            $data['slug'] = static::uniqueSlug($data['name']['en'] ?? 'service');
        }

        return $data;
    }

    public static function unpackDeposit(array $data): array
    {
        $currency = tenant()?->currency ?? 'QAR';
        $data['deposit_percent'] = ($data['deposit_type'] ?? null) === 'percent' ? ((int) $data['deposit_value']) / 100 : null;
        $data['deposit_fixed'] = ($data['deposit_type'] ?? null) === 'fixed' ? MoneyValue::of((int) $data['deposit_value'], $currency)->toDecimal() : null;
        $data['deposit_type'] ??= 'none';

        return $data;
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: Str::lower(Str::random(6));
        $slug = $base;
        $i = 2;
        while (Service::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category')->withCount('variants'))
            ->defaultSort('sort')
            ->reorderable('sort', fn () => static::canManage())
            ->columns([
                ImageColumn::make('image_path')->label('')->disk('public')->square()->width(44),
                TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->searchable()
                    ->description(fn (Service $r) => $r->category?->name),
                TextColumn::make('duration_minutes')->label(__('fields.duration'))->suffix(' '.__('salon.ui.minutes'))->sortable()->alignCenter()
                    ->description(fn (Service $r) => $r->variants_count ? trans_choice('workspace.services.variants_count', $r->variants_count, ['count' => $r->variants_count]) : null),
                Money::column('price')->label(__('fields.price'))
                    ->description(fn (Service $r) => $r->sale_price !== null ? __('workspace.services.on_sale', ['price' => money((int) $r->sale_price)]) : null),
                TextColumn::make('gender')->label(__('workspace.services.gender'))->badge()->color('gray')->formatStateUsing(fn ($state) => __('workspace.service_gender.'.$state))->toggleable(),
                TextColumn::make('deposit_type')->label(__('workspace.services.deposit'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state, Service $r) => $state === 'percent' ? ($r->deposit_value / 100).'%' : money((int) $r->deposit_value)),
                IconColumn::make('online_bookable')->label(__('workspace.services.online'))->boolean(),
                ToggleColumn::make('is_active')->label(__('fields.is_active'))->disabled(fn () => ! static::canManage()),
            ])
            ->filters([
                SelectFilter::make('service_category_id')->label(__('workspace.service_categories.label'))
                    ->options(fn () => ServiceCategory::query()->get()->pluck('name', 'id')),
                SelectFilter::make('gender')->label(__('workspace.services.gender'))->options(static::genderOptions()),
                TernaryFilter::make('online_bookable')->label(__('workspace.services.online_bookable')),
                TernaryFilter::make('is_active')->label(__('fields.is_active')),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateIcon(Heroicon::OutlinedSparkles)
            ->emptyStateHeading(__('workspace.services.empty'))
            ->emptyStateDescription(__('workspace.services.empty_body'));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return (string) $record->name;
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [__('fields.price') => money((int) ($record->sale_price ?? $record->price)), __('fields.duration') => $record->duration_minutes.' '.__('salon.ui.minutes')];
    }

    public static function getRelations(): array
    {
        return [BranchOverridesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
