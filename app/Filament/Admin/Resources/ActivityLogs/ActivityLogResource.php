<?php

namespace App\Filament\Admin\Resources\ActivityLogs;

use App\Filament\Admin\Resources\ActivityLogs\Pages\ManageActivityLogs;
use App\Support\ActivityLabel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns;
use Filament\Tables\Filters;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.platform');
    }

    public static function getModelLabel(): string
    {
        return __('admin.activity.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.activity.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.settings.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false && static::canViewAny();
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            Columns\TextColumn::make('created_at')->label(__('salon.ui.created'))->dateTime()->sortable(),
            Columns\TextColumn::make('description')->label(__('admin.activity.event'))->badge()->color('gray')->searchable()->formatStateUsing(fn ($state) => ActivityLabel::for($state)),
            Columns\TextColumn::make('causer.name')->label(__('admin.activity.by'))->placeholder(__('admin.activity.system')),
            Columns\TextColumn::make('subject_type')->label(__('admin.activity.subject'))->formatStateUsing(fn ($state, $record) => class_basename((string) $state).' #'.$record->subject_id),
            Columns\TextColumn::make('properties')->label(__('admin.activity.details'))->formatStateUsing(fn ($state) => Str::limit(json_encode($state, JSON_UNESCAPED_UNICODE), 120))->wrap()->color('gray'),
        ])->filters([
            Filters\SelectFilter::make('description')->label(__('admin.activity.event'))->options(fn () => Activity::query()->distinct()->limit(100)->pluck('description', 'description')->map(fn ($e) => ActivityLabel::for($e))),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageActivityLogs::route('/')];
    }
}
