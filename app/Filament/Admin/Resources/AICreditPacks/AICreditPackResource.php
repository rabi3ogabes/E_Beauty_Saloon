<?php

namespace App\Filament\Admin\Resources\AICreditPacks;

use App\Domain\AIStudio\Models\AICreditPack;
use App\Filament\Admin\Resources\AICreditPacks\Pages\ManageAICreditPacks;
use App\Filament\Support\Money;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AICreditPackResource extends Resource
{
    protected static ?string $model = AICreditPack::class;

    protected static ?string $slug = 'ai-credit-packs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.ai');
    }

    public static function getModelLabel(): string
    {
        return __('ai_studio.admin.pack');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ai_studio.admin.packs');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.billing.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Translatable::make('name', __('fields.name')),
            TextInput::make('credits')->label(__('ai_studio.admin.credits'))->numeric()->minValue(1)->required(),
            Money::input('price', 'QAR')->label(__('ai_studio.admin.price'))->required(),
            TextInput::make('currency')->default('QAR')->maxLength(3)->required(),
            TextInput::make('sort')->numeric()->default(0),
            Toggle::make('is_featured')->label('Featured'),
            Toggle::make('is_active')->label(__('ai_studio.admin.enabled'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->columns([
            TextColumn::make('name')->label(__('fields.name'))->weight('semibold'),
            TextColumn::make('credits')->label(__('ai_studio.admin.credits'))->numeric()->sortable(),
            Money::column('price')->label(__('ai_studio.admin.price')),
            TextColumn::make('per_credit')->label('/ credit')->state(fn ($record) => money((int) round($record->price / max(1, $record->credits)), $record->currency)),
            IconColumn::make('is_active')->label(__('ai_studio.admin.enabled'))->boolean(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAICreditPacks::route('/')];
    }
}
