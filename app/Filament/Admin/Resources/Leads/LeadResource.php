<?php

namespace App\Filament\Admin\Resources\Leads;

use App\Domain\Websites\Models\Lead;
use App\Filament\Admin\Resources\Leads\Pages\ManageLeads;
use App\Models\User;
use BackedEnum;
use Filament\Actions;
use Filament\Forms\Components;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns;
use Filament\Tables\Filters;
use Filament\Tables\Table;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.growth');
    }

    public static function getModelLabel(): string
    {
        return __('admin.lead.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.lead.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.leads.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return true && static::canViewAny();
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Lead::where('stage', 'new')->count() ?: null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Components\TextInput::make('name')->label(__('fields.name'))->required(),
            Components\TextInput::make('salon_name')->label(__('fields.salon_name')),
            Components\TextInput::make('email')->label(__('fields.email'))->email(),
            Components\TextInput::make('phone')->label(__('fields.phone'))->tel(),
            Components\TextInput::make('branches')->label(__('fields.branches'))->numeric(),
            Components\Select::make('stage')->label(__('fields.stage'))->required()->default('new')->options(static::stages()),
            Components\Select::make('assigned_to')->label(__('fields.assigned_to'))->options(fn () => User::where('is_platform', true)->pluck('name', 'id')),
            Components\TextInput::make('source')->label(__('fields.source')),
            Components\Textarea::make('message')->label(__('fields.message'))->columnSpanFull()->rows(3),
        ]);
    }

    public static function stages(): array
    {
        return collect(['new', 'contacted', 'demo', 'won', 'lost'])->mapWithKeys(fn ($s) => [$s => __('admin.lead.stages.'.$s)])->all();
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            Columns\TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->searchable()->description(fn ($record) => $record->salon_name),
            Columns\TextColumn::make('email')->label(__('fields.email'))->copyable()->searchable(),
            Columns\TextColumn::make('phone')->extraAttributes(['dir' => 'ltr'])->label(__('fields.phone'))->copyable(),
            Columns\SelectColumn::make('stage')->label(__('fields.stage'))->options(static::stages()),
            Columns\TextColumn::make('source')->label(__('fields.source'))->badge()->color('gray')->placeholder('—'),
            Columns\TextColumn::make('created_at')->label(__('salon.ui.created'))->since()->sortable(),
        ])->filters([
            Filters\SelectFilter::make('stage')->label(__('fields.stage'))->options(static::stages()),
        ])->recordActions([Actions\EditAction::make(), Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLeads::route('/')];
    }
}
