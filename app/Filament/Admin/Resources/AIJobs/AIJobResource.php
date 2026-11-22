<?php

namespace App\Filament\Admin\Resources\AIJobs;

use App\Domain\AIStudio\Actions\ProcessAIJob;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Admin\Resources\AIJobs\Pages\ListAIJobs;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Read-only explorer of every AI job across salons (cost, provider, latency, attempts). */
class AIJobResource extends Resource
{
    protected static ?string $model = AIJob::class;

    protected static ?string $slug = 'ai-jobs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.ai');
    }

    public static function getModelLabel(): string
    {
        return __('ai_studio.admin.job');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ai_studio.admin.jobs');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.billing.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->with(['tenant', 'provider']);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('#')->sortable(),
            TextColumn::make('tenant.name')->label(__('ai_studio.admin.tenant'))->searchable(),
            TextColumn::make('task')->label(__('ai_studio.admin.task'))->badge()->color('gray'),
            TextColumn::make('source')->label(__('ai_studio.admin.source'))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('status')->label(__('ai_studio.admin.status'))->badge()
                ->color(fn ($state) => match ($state) {
                    'succeeded' => 'success', 'failed' => 'danger', 'refunded' => 'warning', default => 'gray'
                }),
            TextColumn::make('provider.name')->label(__('ai_studio.admin.provider'))->placeholder('—')->description(fn ($record) => $record->model),
            TextColumn::make('charged_credits')->label(__('ai_studio.admin.credits'))->sortable(),
            TextColumn::make('provider_cost')->label(__('ai_studio.admin.provider_cost'))->formatStateUsing(fn ($state) => '$'.number_format($state / 100, 2))->sortable(),
            TextColumn::make('latency_ms')->label(__('ai_studio.admin.latency_ms'))->suffix(' ms')->placeholder('—')->sortable(),
            TextColumn::make('attempts_count')->label(__('ai_studio.admin.attempts'))->state(fn ($record) => count($record->attempt_log ?? [])),
            TextColumn::make('error')->label(__('ai_studio.admin.error'))->limit(40)->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('created_at')->label(__('salon.ui.created'))->since()->sortable(),
        ])->filters([
            SelectFilter::make('status')->label(__('ai_studio.admin.status'))->options(array_combine(AIJob::STATUSES, AIJob::STATUSES)),
            SelectFilter::make('task')->label(__('ai_studio.admin.task'))->options(fn () => AIJob::withoutGlobalScopes()->distinct()->pluck('task', 'task')->all()),
            SelectFilter::make('provider_driver')->label(__('ai_studio.admin.driver'))->options(fn () => AIJob::withoutGlobalScopes()->whereNotNull('provider_driver')->distinct()->pluck('provider_driver', 'provider_driver')->all()),
            SelectFilter::make('tenant_id')->label(__('ai_studio.admin.tenant'))->options(fn () => Tenant::pluck('name', 'id')->all())->searchable(),
        ])->recordActions([
            Action::make('refund')->label(__('ai_studio.admin.refund'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
                ->visible(fn ($record) => $record->status === 'succeeded' && $record->charged_credits > 0)
                ->requiresConfirmation()
                ->action(function ($record) {
                    app(TenantContext::class)->run($record->tenant, fn () => app(ProcessAIJob::class)->refund($record, 'Refunded by platform support'));
                    Notification::make()->success()->title(__('ai_studio.admin.refunded'))->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAIJobs::route('/')];
    }
}
