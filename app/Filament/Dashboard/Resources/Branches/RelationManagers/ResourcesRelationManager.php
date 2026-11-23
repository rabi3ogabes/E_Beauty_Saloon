<?php

namespace App\Filament\Dashboard\Resources\Branches\RelationManagers;

use App\Filament\Dashboard\Concerns\WorkspaceRelationManager;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ResourcesRelationManager extends RelationManager
{
    use WorkspaceRelationManager;

    protected static string $relationship = 'resources';

    protected static string $managePermission = 'branches.manage';

    public const TYPES = ['chair', 'room', 'pedicure_station', 'wash_basin', 'bed'];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('workspace.branches.resources');
    }

    public static function typeOptions(): array
    {
        return collect(self::TYPES)->mapWithKeys(fn ($t) => [$t => __('workspace.resource_types.'.$t)])->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('fields.name'))->required()->maxLength(80)->placeholder(__('workspace.branches.resource_placeholder')),
            Select::make('type')->label(__('fields.type'))->options(static::typeOptions())->required()->native(false),
            TextInput::make('capacity')->label(__('workspace.branches.capacity'))->numeric()->minValue(1)->default(1)->required(),
            Toggle::make('is_active')->label(__('fields.is_active'))->default(true)->inline(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->searchable(),
            TextColumn::make('type')->label(__('fields.type'))->badge()->formatStateUsing(fn ($state) => __('workspace.resource_types.'.$state)),
            TextColumn::make('capacity')->label(__('workspace.branches.capacity'))->alignCenter(),
            IconColumn::make('is_active')->label(__('fields.is_active'))->boolean(),
        ])->headerActions([
            CreateAction::make()->label(__('workspace.branches.add_resource'))
                ->mutateDataUsing(fn (array $data) => $data + ['tenant_id' => $this->getOwnerRecord()->tenant_id]),
        ])->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading(__('workspace.branches.resources_empty'))->emptyStateDescription(__('workspace.branches.resources_empty_body'));
    }
}
