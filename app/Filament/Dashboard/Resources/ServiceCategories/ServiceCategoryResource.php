<?php

namespace App\Filament\Dashboard\Resources\ServiceCategories;

use App\Domain\Catalog\Models\ServiceCategory;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\ServiceCategories\Pages\ManageServiceCategories;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ServiceCategoryResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = ServiceCategory::class;

    protected static string $viewPermission = 'catalog.view';

    protected static string $managePermission = 'catalog.manage';

    protected static string $navGroup = 'catalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('workspace.service_categories.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.service_categories.plural');
    }

    public const ICONS = ['heroicon-o-scissors', 'heroicon-o-hand-raised', 'heroicon-o-eye', 'heroicon-o-face-smile', 'heroicon-o-sparkles', 'heroicon-o-sun', 'heroicon-o-heart', 'heroicon-o-star', 'heroicon-o-gift'];

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Translatable::make('name', __('fields.name')),
            TextInput::make('slug')->label(__('fields.slug'))->alphaDash()->maxLength(60)->default(fn () => Str::lower(Str::random(6)))->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', tenant()?->id)),
            Select::make('icon')->label(__('fields.icon'))->native(false)->options(collect(self::ICONS)->mapWithKeys(fn ($i) => [$i => Str::of($i)->after('heroicon-o-')->headline()->toString()])),
            FileUpload::make('image_path')->label(__('workspace.common.image'))->image()->disk('public')->directory('catalog/categories')->maxSize(4096)->columnSpanFull(),
            Toggle::make('is_active')->label(__('fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort', fn () => static::canManage())->columns([
            ImageColumn::make('image_path')->label('')->disk('public')->square()->width(40),
            TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->searchable()->icon(fn ($record) => $record->icon),
            TextColumn::make('services_count')->counts('services')->label(__('workspace.services.plural'))->alignCenter(),
            IconColumn::make('is_active')->label(__('fields.is_active'))->boolean(),
        ])->recordActions([EditAction::make()->slideOver(), DeleteAction::make()])
            ->emptyStateIcon(Heroicon::OutlinedSwatch)
            ->emptyStateHeading(__('workspace.service_categories.empty'))
            ->emptyStateDescription(__('workspace.service_categories.empty_body'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageServiceCategories::route('/')];
    }
}
