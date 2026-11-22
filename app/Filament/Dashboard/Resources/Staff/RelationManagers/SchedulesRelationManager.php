<?php

namespace App\Filament\Dashboard\Resources\Staff\RelationManagers;

use App\Domain\Branches\Models\Branch;
use App\Filament\Dashboard\Concerns\WorkspaceRelationManager;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Weekly working schedule per branch, with breaks (staff_schedules). */
class SchedulesRelationManager extends RelationManager
{
    use WorkspaceRelationManager;

    protected static string $relationship = 'schedules';

    protected static string $managePermission = 'staff.schedules';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('workspace.staff.schedule');
    }

    public static function weekdayOptions(): array
    {
        return collect(Branch::WEEKDAYS)->mapWithKeys(fn ($d, $i) => [$i => __('workspace.weekdays.'.$d)])->all();
    }

    protected function branchOptions(): array
    {
        return $this->getOwnerRecord()->branches()->get()->mapWithKeys(fn ($b) => [$b->id => $b->name])->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('branch_id')->label(__('salon.ui.branch'))->options(fn () => $this->branchOptions())->required()->native(false)
                ->default(fn () => array_key_first($this->branchOptions())),
            Select::make('weekday')->label(__('workspace.common.weekday'))->options(static::weekdayOptions())->required()->native(false)
                ->visibleOn('edit'),
            CheckboxList::make('weekdays')->label(__('workspace.staff.weekdays'))->options(static::weekdayOptions())->columns(4)->required()
                ->visibleOn('create')->default([0, 1, 2, 3, 4, 6])->columnSpanFull(),
            TimePicker::make('starts_at')->label(__('workspace.staff.starts'))->seconds(false)->required()->default('10:00'),
            TimePicker::make('ends_at')->label(__('workspace.staff.ends'))->seconds(false)->required()->default('19:00')->after('starts_at'),
            Repeater::make('breaks')->label(__('workspace.branches.breaks'))->columns(2)->columnSpanFull()->defaultItems(0)->reorderable(false)
                ->addActionLabel(__('workspace.branches.add_break'))
                ->schema([
                    TimePicker::make('from')->label(__('workspace.common.from'))->seconds(false)->required(),
                    TimePicker::make('to')->label(__('workspace.common.to'))->seconds(false)->required()->after('from'),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('branch')->orderBy('weekday')->orderBy('starts_at'))
            ->columns([
                TextColumn::make('weekday')->label(__('workspace.common.weekday'))->formatStateUsing(fn ($state) => __('workspace.weekdays.'.Branch::WEEKDAYS[$state]))->weight('semibold'),
                TextColumn::make('branch.name')->label(__('salon.ui.branch'))->badge()->color('gray'),
                TextColumn::make('starts_at')->label(__('workspace.staff.hours'))->state(fn ($record) => substr($record->starts_at, 0, 5).'–'.substr($record->ends_at, 0, 5)),
                TextColumn::make('breaks')->label(__('workspace.branches.breaks'))
                    ->state(fn ($record) => collect($record->breaks ?? [])->map(fn ($b) => ($b[0] ?? '').'–'.($b[1] ?? ''))->join(', ') ?: '—'),
            ])
            ->filters([SelectFilter::make('branch_id')->label(__('salon.ui.branch'))->options(fn () => $this->branchOptions())])
            ->headerActions([
                CreateAction::make()->label(__('workspace.staff.add_shift'))
                    ->using(function (array $data) {
                        $created = null;
                        foreach ((array) ($data['weekdays'] ?? []) as $day) {
                            $created = $this->getOwnerRecord()->schedules()->create([
                                'tenant_id' => $this->getOwnerRecord()->tenant_id, 'branch_id' => $data['branch_id'], 'weekday' => (int) $day,
                                'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'breaks' => static::packBreaks($data['breaks'] ?? []),
                            ]);
                        }

                        return $created;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data) => ['breaks' => collect($data['breaks'] ?? [])->map(fn ($b) => ['from' => $b[0] ?? null, 'to' => $b[1] ?? null])->all()] + $data)
                    ->mutateDataUsing(fn (array $data) => ['breaks' => static::packBreaks($data['breaks'] ?? [])] + $data),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('workspace.staff.schedule_empty'))->emptyStateDescription(__('workspace.staff.schedule_empty_body'));
    }

    public static function packBreaks(array $rows): array
    {
        return collect($rows)->filter(fn ($b) => filled($b['from'] ?? null) && filled($b['to'] ?? null))
            ->map(fn ($b) => [substr((string) $b['from'], 0, 5), substr((string) $b['to'], 0, 5)])->values()->all();
    }
}
