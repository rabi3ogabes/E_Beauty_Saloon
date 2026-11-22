<?php

namespace App\Filament\Dashboard\Resources\Branches\RelationManagers;

use App\Filament\Dashboard\Concerns\WorkspaceRelationManager;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class HolidaysRelationManager extends RelationManager
{
    use WorkspaceRelationManager;

    protected static string $relationship = 'holidays';

    protected static string $managePermission = 'branches.manage';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('workspace.branches.holidays');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            DatePicker::make('date')->label(__('workspace.common.date'))->required()->native(false)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('branch_id', $this->getOwnerRecord()->id)),
            TextInput::make('name')->label(__('workspace.branches.holiday_name'))->placeholder(__('workspace.branches.holiday_placeholder'))->maxLength(120),
            Toggle::make('partial')->label(__('workspace.branches.partial_day'))->helperText(__('workspace.branches.partial_day_help'))->live()->dehydrated(false)
                ->afterStateHydrated(fn ($component, $record) => $component->state((bool) $record?->opens_at))->columnSpanFull(),
            TimePicker::make('opens_at')->label(__('workspace.branches.opens'))->seconds(false)->visible(fn (Get $get) => (bool) $get('partial'))->required(fn (Get $get) => (bool) $get('partial')),
            TimePicker::make('closes_at')->label(__('workspace.branches.closes'))->seconds(false)->visible(fn (Get $get) => (bool) $get('partial'))->required(fn (Get $get) => (bool) $get('partial'))->after('opens_at'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('date')->columns([
            TextColumn::make('date')->label(__('workspace.common.date'))->date()->sortable()->weight('semibold'),
            TextColumn::make('name')->label(__('workspace.branches.holiday_name'))->placeholder('—'),
            TextColumn::make('opens_at')->label(__('workspace.branches.hours'))
                ->state(fn ($record) => $record->opens_at ? substr($record->opens_at, 0, 5).'–'.substr((string) $record->closes_at, 0, 5) : __('workspace.branches.closed_all_day'))
                ->badge()->color(fn ($record) => $record->opens_at ? 'warning' : 'gray'),
        ])->headerActions([
            CreateAction::make()->label(__('workspace.branches.add_holiday'))->mutateDataUsing(fn (array $data) => $this->cleanHoliday($data)),
        ])->recordActions([
            EditAction::make()->mutateDataUsing(fn (array $data) => $this->cleanHoliday($data)),
            DeleteAction::make(),
        ])->emptyStateHeading(__('workspace.branches.holidays_empty'))->emptyStateDescription(__('workspace.branches.holidays_empty_body'));
    }

    protected function cleanHoliday(array $data): array
    {
        $data['tenant_id'] = $this->getOwnerRecord()->tenant_id;
        if (empty($data['opens_at']) || empty($data['closes_at'])) {
            $data['opens_at'] = $data['closes_at'] = null;
        }

        return $data;
    }
}
