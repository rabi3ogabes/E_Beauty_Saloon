<?php

namespace App\Filament\Dashboard\Resources\Marketing\Automations;

use App\Domain\Marketing\Automation\TriggerRegistry;
use App\Domain\Marketing\Models\Automation;
use App\Filament\Dashboard\Resources\Marketing\Automations\Pages\EditAutomationFlow;
use App\Filament\Dashboard\Resources\Marketing\Automations\Pages\ListAutomations;
use App\Filament\Marketing\GatedResource;
use App\Filament\Marketing\MarketingAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class AutomationResource extends Resource
{
    use GatedResource;

    protected static ?string $model = Automation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?int $navigationSort = 12;

    public static function getModelLabel(): string
    {
        return __('marketing.automations.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('marketing.automations.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('marketing.common.name'))->required()->maxLength(120),
            Select::make('trigger')->label(__('marketing.automations.trigger'))->options(fn () => app(TriggerRegistry::class)->options())->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id')
            ->columns([
                TextColumn::make('name')->label(__('marketing.common.name'))->weight('semibold')->searchable()
                    ->description(fn (Automation $r) => $r->recipe ? __('marketing.automations.recipes.'.$r->recipe.'.description') : null)->wrap(),
                TextColumn::make('trigger')->label(__('marketing.automations.trigger'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => app(TriggerRegistry::class)->options()[$state] ?? $state),
                TextColumn::make('steps')->label(__('marketing.automations.builder'))->state(fn (Automation $r) => collect($r->steps ?? [])->map(fn ($s) => __('marketing.automations.step_types.'.($s['type'] ?? 'wait')))->join(' → '))->wrap()->color('gray'),
                TextColumn::make('runs_count')->label(__('marketing.automations.runs'))->numeric()->alignEnd(),
                TextColumn::make('last_run_at')->label(__('marketing.automations.last_run'))->since()->placeholder('—'),
                ToggleColumn::make('is_active')->label(__('marketing.common.active'))->disabled(fn () => ! MarketingAccess::manage()),
            ])
            ->recordUrl(fn (Automation $record) => MarketingAccess::manage() ? static::getUrl('flow', ['record' => $record]) : null)
            ->recordActions([
                Action::make('flow')->label(__('marketing.automations.edit_flow'))->icon('heroicon-o-squares-plus')
                    ->url(fn (Automation $record) => static::getUrl('flow', ['record' => $record]))->visible(fn () => MarketingAccess::manage()),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('marketing.automations.empty'))->emptyStateDescription(__('marketing.automations.empty_help'))->emptyStateIcon('heroicon-o-bolt');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAutomations::route('/'),
            'flow' => EditAutomationFlow::route('/{record}/flow'),
        ];
    }
}
