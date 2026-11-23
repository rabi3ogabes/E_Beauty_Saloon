<?php

namespace App\Filament\Dashboard\Resources\Products;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\Brands\BrandResource;
use App\Filament\Dashboard\Resources\Products\Pages\CreateProduct;
use App\Filament\Dashboard\Resources\Products\Pages\EditProduct;
use App\Filament\Dashboard\Resources\Products\Pages\ListProducts;
use App\Filament\Dashboard\Resources\Products\RelationManagers\StockRelationManager;
use App\Filament\Support\Money;
use App\Filament\Support\Translatable;
use App\Filament\Support\Uploads;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = Product::class;

    protected static string $viewPermission = 'catalog.view';

    protected static string $managePermission = 'catalog.manage';

    protected static string $navGroup = 'catalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('workspace.products.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.products.plural');
    }

    public static function usageOptions(): array
    {
        return collect(['retail', 'professional', 'both'])->mapWithKeys(fn ($u) => [$u => __('workspace.products.usage_options.'.$u)])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Grid::make(1)->columnSpan(['default' => 3, 'lg' => 2])->schema([
                Section::make(__('workspace.products.details'))->columns(2)->schema([
                    Translatable::make('name', __('fields.name')),
                    Translatable::make('description', __('fields.description'), required: false, textarea: true),
                    Select::make('brand_id')->label(__('workspace.brands.label'))->native(false)->searchable()
                        ->options(fn () => Brand::query()->orderBy('name')->pluck('name', 'id'))
                        ->createOptionForm(BrandResource::fields())->createOptionUsing(fn (array $data) => Brand::create($data)->id),
                    Select::make('product_category_id')->label(__('workspace.product_categories.label'))->native(false)->searchable()
                        ->options(fn () => ProductCategory::query()->get()->pluck('name', 'id')),
                    TextInput::make('slug')->label(__('fields.slug'))->alphaDash()->maxLength(80)
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', tenant()?->id)),
                    Select::make('usage')->label(__('workspace.products.usage'))->options(static::usageOptions())->default('retail')->required()->native(false)
                        ->helperText(__('workspace.products.usage_help')),
                ]),
                Section::make(__('workspace.products.variants'))->description(__('workspace.products.variants_help'))->schema([
                    Repeater::make('variants')->relationship()->hiddenLabel()->columns(4)->minItems(1)->defaultItems(1)
                        ->addActionLabel(__('workspace.products.add_variant'))->collapsible()
                        ->itemLabel(fn (array $state) => trim(($state['name']['en'] ?? '').' · '.($state['sku'] ?? ''), ' ·') ?: null)
                        ->schema([
                            TextInput::make('name.en')->label(__('workspace.products.variant_name').' · English')->placeholder('250ml / Rose Nude')->columnSpan(2)
                                ->afterStateHydrated(fn ($component, $record) => $record ? $component->state($record->getTranslation('name', 'en', false) ?: null) : null),
                            TextInput::make('name.ar')->label(__('workspace.products.variant_name').' · العربية')->extraInputAttributes(['dir' => 'rtl'])->columnSpan(2)
                                ->afterStateHydrated(fn ($component, $record) => $record ? $component->state($record->getTranslation('name', 'ar', false) ?: null) : null),
                            TextInput::make('sku')->label('SKU')->required()->maxLength(60)->distinct()
                                ->unique('product_variants', 'sku', ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', tenant()?->id))
                                ->default(fn () => 'SKU-'.Str::upper(Str::random(6))),
                            TextInput::make('barcode')->label(__('workspace.products.barcode'))->maxLength(60),
                            ColorPicker::make('swatch')->label(__('workspace.products.swatch')),
                            Toggle::make('is_default')->label(__('workspace.products.default_variant'))->inline(false),
                            Money::input('cost')->label(__('workspace.products.cost'))->default(0),
                            Money::input('price')->label(__('fields.price'))->required(),
                            Money::input('sale_price')->label(__('fields.sale_price')),
                        ]),
                ]),
                Section::make(__('workspace.products.attributes'))->description(__('workspace.products.attributes_help'))->collapsible()->collapsed()->schema([
                    KeyValue::make('attributes')->hiddenLabel()->keyLabel(__('workspace.products.attribute'))->valueLabel(__('fields.value'))
                        ->keyPlaceholder('hair_type')->valuePlaceholder('curly'),
                ]),
            ]),
            Grid::make(1)->columnSpan(['default' => 3, 'lg' => 1])->schema([
                Section::make(__('workspace.products.selling'))->schema([
                    Toggle::make('is_active')->label(__('fields.is_active'))->default(true),
                    Toggle::make('sold_online')->label(__('workspace.products.sold_online'))->default(true),
                    Toggle::make('track_stock')->label(__('workspace.products.track_stock'))->helperText(__('workspace.products.track_stock_help'))->default(true),
                    TextInput::make('tax_percent')->label(__('workspace.branches.tax_percent'))->numeric()->minValue(0)->maxValue(100)->suffix('%')
                        ->placeholder(__('workspace.products.tax_default')),
                ]),
                Section::make(__('workspace.products.media'))->schema([
                    FileUpload::make('images')->label(__('workspace.products.images'))->image()->acceptedFileTypes(Uploads::IMAGE_TYPES)->multiple()->reorderable()->maxFiles(8)
                        ->disk('public')->directory('catalog/products')->maxSize(4096),
                    TextInput::make('video_url')->label(__('workspace.services.video'))->url(),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['brand', 'category', 'variants'])->withSum('stockRows as stock_on_hand', 'qty'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('images')->label('')->disk('public')->square()->width(44)->limit(1),
                TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->searchable()
                    ->description(fn (Product $r) => collect([$r->brand?->name, $r->category?->name])->filter()->join(' · ') ?: null),
                TextColumn::make('variants.sku')->label('SKU')->badge()->color('gray')->limitList(2)->searchable()->toggleable(),
                TextColumn::make('price')->label(__('fields.price'))->alignEnd()
                    ->state(fn (Product $r) => $r->variants->map->effectivePrice()->min())
                    ->formatStateUsing(fn ($state, Product $r) => $r->variants->count() > 1 ? __('workspace.products.from', ['price' => money((int) $state)]) : money((int) $state)),
                TextColumn::make('stock_on_hand')->label(__('workspace.inventory.on_hand'))->alignCenter()->placeholder('0')
                    ->badge()->color(fn ($state, Product $r) => ! $r->track_stock ? 'gray' : ((int) $state <= 0 ? 'danger' : 'success')),
                TextColumn::make('usage')->label(__('workspace.products.usage'))->badge()->color('gray')->formatStateUsing(fn ($state) => __('workspace.products.usage_options.'.$state))->toggleable(),
                IconColumn::make('sold_online')->label(__('workspace.products.online'))->boolean()->toggleable(),
                IconColumn::make('is_active')->label(__('fields.is_active'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('brand_id')->label(__('workspace.brands.label'))->options(fn () => Brand::query()->pluck('name', 'id')),
                SelectFilter::make('product_category_id')->label(__('workspace.product_categories.label'))->options(fn () => ProductCategory::query()->get()->pluck('name', 'id')),
                SelectFilter::make('usage')->label(__('workspace.products.usage'))->options(static::usageOptions()),
                TernaryFilter::make('is_active')->label(__('fields.is_active')),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateIcon(Heroicon::OutlinedShoppingBag)
            ->emptyStateHeading(__('workspace.products.empty'))
            ->emptyStateDescription(__('workspace.products.empty_body'));
    }

    public static function prepareSlug(array $data): array
    {
        if (blank($data['slug'] ?? null)) {
            $base = Str::slug($data['name']['en'] ?? '') ?: Str::lower(Str::random(6));
            $slug = $base;
            $i = 2;
            while (Product::withTrashed()->where('slug', $slug)->exists()) {
                $slug = $base.'-'.$i++;
            }
            $data['slug'] = $slug;
        }

        return $data;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'variants.sku', 'variants.barcode'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return (string) $record->name;
    }

    public static function getRelations(): array
    {
        return [StockRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
