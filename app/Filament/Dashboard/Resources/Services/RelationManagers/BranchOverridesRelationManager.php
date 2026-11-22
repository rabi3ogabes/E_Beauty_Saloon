<?php

namespace App\Filament\Dashboard\Resources\Services\RelationManagers;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Actions\SetBranchServiceOverride;
use App\Filament\Dashboard\Concerns\WorkspaceRelationManager;
use App\Filament\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Per-branch availability, price and duration (branch_service pivot). Empty = inherit the service defaults. */
class BranchOverridesRelationManager extends RelationManager
{
    use WorkspaceRelationManager;

    protected static string $relationship = 'branches';

    protected static string $managePermission = 'catalog.prices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('workspace.services.branch_overrides');
    }

    protected function overrideFields(bool $withBranch): array
    {
        return array_values(array_filter([
            $withBranch ? Select::make('branch_id')->label(__('salon.ui.branch'))->required()->native(false)
                ->options(fn () => Branch::query()->orderBy('sort')->get()->mapWithKeys(fn ($b) => [$b->id => $b->name])) : null,
            Toggle::make('is_active')->label(__('workspace.services.offered_here'))->default(true),
            Money::input('price')->label(__('workspace.services.branch_price'))->placeholder(money((int) ($this->getOwnerRecord()->sale_price ?? $this->getOwnerRecord()->price)))
                ->helperText(__('workspace.common.blank_inherits')),
            TextInput::make('duration_minutes')->label(__('workspace.services.branch_duration'))->numeric()->minValue(5)->suffix(__('salon.ui.minutes'))
                ->placeholder((string) $this->getOwnerRecord()->duration_minutes)->helperText(__('workspace.common.blank_inherits')),
        ]));
    }

    protected function save(int $branchId, array $data): void
    {
        app(SetBranchServiceOverride::class)->handle(
            $this->getOwnerRecord(), Branch::findOrFail($branchId), (bool) ($data['is_active'] ?? true),
            filled($data['price'] ?? null) ? (int) $data['price'] : null,
            filled($data['duration_minutes'] ?? null) ? (int) $data['duration_minutes'] : null,
        );
        Notification::make()->success()->title(__('workspace.services.override_saved'))->send();
    }

    public function table(Table $table): Table
    {
        $service = $this->getOwnerRecord();

        return $table->recordTitleAttribute('name')->columns([
            TextColumn::make('name')->label(__('salon.ui.branch'))->weight('semibold'),
            IconColumn::make('pivot.is_active')->label(__('workspace.services.offered_here'))->boolean(),
            TextColumn::make('pivot.price')->label(__('fields.price'))
                ->state(fn ($record) => $service->priceAt($record))->formatStateUsing(fn ($state) => money((int) $state))
                ->description(fn ($record) => $record->pivot->price === null ? __('workspace.common.inherited') : __('workspace.common.override')),
            TextColumn::make('pivot.duration_minutes')->label(__('fields.duration'))
                ->state(fn ($record) => $service->durationAt($record).' '.__('salon.ui.minutes'))
                ->description(fn ($record) => $record->pivot->duration_minutes === null ? __('workspace.common.inherited') : __('workspace.common.override')),
        ])->headerActions([
            Action::make('addOverride')->label(__('workspace.services.add_override'))->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => ! $this->isReadOnly())
                ->schema($this->overrideFields(true))
                ->action(fn (array $data) => $this->save((int) $data['branch_id'], $data)),
        ])->recordActions([
            Action::make('editOverride')->label(__('salon.ui.edit'))->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn () => ! $this->isReadOnly())
                ->fillForm(fn ($record) => ['is_active' => (bool) $record->pivot->is_active, 'price' => $record->pivot->price, 'duration_minutes' => $record->pivot->duration_minutes])
                ->schema($this->overrideFields(false))
                ->action(fn ($record, array $data) => $this->save((int) $record->id, $data)),
            Action::make('resetOverride')->label(__('workspace.services.reset'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                ->visible(fn () => ! $this->isReadOnly())->requiresConfirmation()
                ->action(function ($record) use ($service) {
                    $service->branches()->detach($record->id);
                    Notification::make()->success()->title(__('workspace.services.override_reset'))->send();
                }),
        ])->emptyStateHeading(__('workspace.services.overrides_empty'))->emptyStateDescription(__('workspace.services.overrides_empty_body'));
    }
}
