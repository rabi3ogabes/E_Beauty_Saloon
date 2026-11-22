<?php

namespace App\Filament\Dashboard\Resources\ProductCategories;

use App\Domain\Catalog\Models\ProductCategory;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\ProductCategories\Pages\ManageProductCategories;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProductCategoryResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = ProductCategory::class;

    protected static string $viewPermission = 'catalog.view';

    protected static string $managePermission = 'catalog.manage';

    protected static string $navGroup = 'catalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('workspace.product_categories.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.product_categories.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Translatable::make('name', __('fields.name')),
            TextInput::make('slug')->label(__('fields.slug'))->alphaDash()->required()->default(fn () => Str::lower(Str::random(6)))
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', tenant()?->id)),
            Select::make('parent_id')->label(__('workspace.product_categories.parent'))->native(false)->placeholder(__('salon.ui.none'))
                ->options(fn ($record) => ProductCategory::query()->when($record, fn ($q) => $q->whereKeyNot($record->id))->get()->pluck('name', 'id')),
            FileUpload::make('image_path')->label(__('workspace.common.image'))->image()->disk('public')->directory('catalog/product-categories')->maxSize(4096)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort', fn () => static::canManage())->columns([
            ImageColumn::make('image_path')->label('')->disk('public')->square()->width(40),
            TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->searchable(),
            TextColumn::make('parent_id')->label(__('workspace.product_categories.parent'))->placeholder('—')
                ->formatStateUsing(fn ($state) => ProductCategory::find($state)?->name),
        ])->recordActions([EditAction::make()->slideOver(), DeleteAction::make()])
            ->emptyStateIcon(Heroicon::OutlinedRectangleStack)
            ->emptyStateHeading(__('workspace.product_categories.empty'))
            ->emptyStateDescription(__('workspace.product_categories.empty_body'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageProductCategories::route('/')];
    }
}
