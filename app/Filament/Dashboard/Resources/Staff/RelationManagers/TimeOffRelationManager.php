<?php

namespace App\Filament\Dashboard\Resources\Staff\RelationManagers;

use App\Filament\Dashboard\Concerns\WorkspaceRelationManager;
use App\Filament\Dashboard\Resources\TimeOff\TimeOffResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TimeOffRelationManager extends RelationManager
{
    use WorkspaceRelationManager;

    protected static string $relationship = 'timeOff';

    protected static string $managePermission = 'staff.schedules';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('workspace.time_off.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(TimeOffResource::fields(withStaff: false));
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('starts_at', 'desc')
            ->columns(TimeOffResource::columns(withStaff: false))
            ->headerActions([
                CreateAction::make()->label(__('workspace.time_off.add'))
                    ->mutateDataUsing(fn (array $data) => $data + ['tenant_id' => $this->getOwnerRecord()->tenant_id, 'status' => 'approved', 'approved_by' => auth()->id()]),
            ])
            ->recordActions([...TimeOffResource::reviewActions(), DeleteAction::make()])
            ->emptyStateHeading(__('workspace.time_off.empty'));
    }
}
