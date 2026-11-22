<?php

namespace App\Filament\Admin\Resources\AITaskPrices;

use App\Domain\AIStudio\Models\AIProvider;
use App\Domain\AIStudio\Models\AITaskPrice;
use App\Filament\Admin\Resources\AITaskPrices\Pages\ManageAITaskPrices;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AITaskPriceResource extends Resource
{
    protected static ?string $model = AITaskPrice::class;

    protected static ?string $slug = 'ai-task-prices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.ai');
    }

    public static function getModelLabel(): string
    {
        return __('ai_studio.admin.task_price');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ai_studio.admin.task_prices');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.settings.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('task')->label(__('ai_studio.admin.task'))->required()->alphaDash()->unique(ignoreRecord: true)->maxLength(40),
            Select::make('capability')->label(__('ai_studio.admin.capability'))->options(['image' => 'Image', 'text' => 'Text', 'video' => 'Video'])->required()->default('image'),
            Translatable::make('name', __('fields.name')),
            TextInput::make('credits')->label(__('ai_studio.admin.credits'))->numeric()->minValue(0)->required(),
            Select::make('preferred_providers')->label(__('ai_studio.admin.preferred'))->multiple()->options(array_combine(AIProvider::DRIVERS, AIProvider::DRIVERS)),
            Toggle::make('is_active')->label(__('ai_studio.admin.enabled'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort')->columns([
            TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->description(fn ($record) => $record->task),
            TextColumn::make('capability')->label(__('ai_studio.admin.capability'))->badge()->color('gray'),
            TextColumn::make('credits')->label(__('ai_studio.admin.credits'))->sortable(),
            TextColumn::make('preferred_providers')->label(__('ai_studio.admin.preferred'))->badge(),
            IconColumn::make('is_active')->label(__('ai_studio.admin.enabled'))->boolean(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAITaskPrices::route('/')];
    }
}
