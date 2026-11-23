<?php

namespace App\Filament\Admin\Resources\AIProviders;

use App\Domain\AIStudio\DTOs\ImageRequest;
use App\Domain\AIStudio\Exceptions\ProviderException;
use App\Domain\AIStudio\Models\AIProvider;
use App\Domain\AIStudio\Providers\ProviderFactory;
use App\Filament\Admin\Resources\AIProviders\Pages\ManageAIProviders;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class AIProviderResource extends Resource
{
    protected static ?string $model = AIProvider::class;

    protected static ?string $slug = 'ai-providers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.ai');
    }

    public static function getModelLabel(): string
    {
        return __('ai_studio.admin.provider');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ai_studio.admin.providers');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.settings.manage') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('tenant_id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make()->columnSpanFull()->columns(2)->schema([
                Select::make('driver')->label(__('ai_studio.admin.driver'))->required()->live()
                    ->options(array_combine(AIProvider::DRIVERS, ['Google Gemini', 'OpenAI', 'Higgsfield', 'Anthropic Claude', 'Generic HTTP'])),
                TextInput::make('name')->label(__('fields.name'))->required()->maxLength(80),
                TextInput::make('api_key')->label(__('ai_studio.admin.api_key'))->password()->revealable(false)
                    ->helperText(__('ai_studio.admin.api_key_help'))
                    ->dehydrated(fn ($state) => filled($state))
                    ->formatStateUsing(fn () => null),
                TextInput::make('base_url')->label(__('ai_studio.admin.base_url'))->url()
                    ->placeholder(fn ($get) => config('ai.providers.'.$get('driver').'.base_url')),
                TextInput::make('models.image')->label(__('ai_studio.admin.model_image'))
                    ->placeholder(fn ($get) => config('ai.providers.'.$get('driver').'.models.image')),
                TextInput::make('models.text')->label(__('ai_studio.admin.model_text'))
                    ->placeholder(fn ($get) => config('ai.providers.'.$get('driver').'.models.text')),
                CheckboxList::make('capabilities')->label(__('ai_studio.admin.capabilities'))->options(['image' => 'Image', 'text' => 'Text / vision', 'video' => 'Video'])->columns(3),
                TextInput::make('priority')->label(__('ai_studio.admin.priority'))->numeric()->minValue(0)->default(10)->required(),
                TextInput::make('unit_costs.image')->label(__('ai_studio.admin.unit_cost_image'))->numeric()->minValue(0)->default(0),
                TextInput::make('unit_costs.text')->label(__('ai_studio.admin.unit_cost_text'))->numeric()->minValue(0)->default(0),
                TextInput::make('timeout_seconds')->label(__('ai_studio.admin.timeout'))->numeric()->minValue(5)->maxValue(600)->default(60),
                Toggle::make('enabled')->label(__('ai_studio.admin.enabled'))->default(true)->inline(false),
                KeyValue::make('options')->label('Options')->columnSpanFull()->visible(fn ($get) => in_array($get('driver'), ['http', 'higgsfield', 'anthropic'], true)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('priority')->columns([
            TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->description(fn ($record) => $record->driver)->searchable(),
            TextColumn::make('priority')->label(__('ai_studio.admin.priority'))->sortable(),
            TextColumn::make('health_status')->label(__('ai_studio.admin.health'))->badge()
                ->color(fn ($state) => match ($state) {
                    'healthy' => 'success', 'degraded' => 'warning', default => 'danger'
                })
                ->description(fn ($record) => $record->last_error ? Str::limit($record->last_error, 40) : null),
            TextColumn::make('latency_ms_ema')->label(__('ai_studio.admin.latency'))->suffix(' ms')->placeholder('—')->sortable(),
            TextColumn::make('total_calls')->label(__('ai_studio.admin.jobs_count'))
                ->formatStateUsing(fn ($state, $record) => number_format($state).($record->total_failures ? ' · '.number_format($record->total_failures).' ✕' : '')),
            IconColumn::make('enabled')->label(__('ai_studio.admin.enabled'))->boolean(),
        ])->recordActions([
            Action::make('health')->label(__('ai_studio.admin.check_health'))->icon(Heroicon::OutlinedHeart)
                ->action(function ($record) {
                    $started = hrtime(true);
                    try {
                        $driver = app(ProviderFactory::class)->make($record);
                        $record->supports('text')
                            ? $driver->writeCaption('health check', 'short', 'en')
                            : $driver->generateImage(new ImageRequest('A single champagne-gold dot on ivory, minimal.'));
                        $ms = (int) ((hrtime(true) - $started) / 1e6);
                        $record->recordSuccess($ms);
                        Notification::make()->success()->title(__('ai_studio.admin.health_ok', ['ms' => $ms]))->send();
                    } catch (ProviderException $e) {
                        $record->recordFailure($e->getMessage());
                        Notification::make()->danger()->title(__('ai_studio.admin.health_failed', ['error' => Str::limit($e->getMessage(), 120)]))->send();
                    }
                }),
            EditAction::make(),
            DeleteAction::make(),
        ])->emptyStateHeading(__('ai_studio.errors.no_provider'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageAIProviders::route('/')];
    }
}
