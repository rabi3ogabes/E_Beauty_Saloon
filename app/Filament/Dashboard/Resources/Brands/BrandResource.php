<?php

namespace App\Filament\Dashboard\Resources\Brands;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Product;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\Brands\Pages\ManageBrands;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class BrandResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = Brand::class;

    protected static string $viewPermission = 'catalog.view';

    protected static string $managePermission = 'catalog.manage';

    protected static string $navGroup = 'catalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('workspace.brands.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.brands.plural');
    }

    public static function fields(): array
    {
        return [
            TextInput::make('name')->label(__('fields.name'))->required()->maxLength(120)->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, Get $get, ?string $state) => blank($get('slug')) ? $set('slug', Str::slug((string) $state)) : null),
            TextInput::make('slug')->label(__('fields.slug'))->alphaDash()->required()
                ->unique('brands', 'slug', ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', tenant()?->id)),
            FileUpload::make('logo_path')->label(__('workspace.brands.logo'))->image()->disk('public')->directory('catalog/brands')->maxSize(2048),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::fields());
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            ImageColumn::make('logo_path')->label('')->disk('public')->width(48),
            TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->searchable()->sortable(),
            TextColumn::make('products_count')->label(__('workspace.products.plural'))->alignCenter()
                ->state(fn (Brand $r) => Product::where('brand_id', $r->id)->count()),
        ])->recordActions([EditAction::make()->slideOver(), DeleteAction::make()])
            ->emptyStateIcon(Heroicon::OutlinedTag)
            ->emptyStateHeading(__('workspace.brands.empty'))
            ->emptyStateDescription(__('workspace.brands.empty_body'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageBrands::route('/')];
    }
}
