<?php

namespace App\Filament\Admin\Resources\ImpersonationLogs;

use App\Domain\Tenancy\Models\ImpersonationLog;
use App\Filament\Admin\Resources\ImpersonationLogs\Pages\ManageImpersonationLogs;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns;
use Filament\Tables\Table;

class ImpersonationLogResource extends Resource
{
    protected static ?string $model = ImpersonationLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.platform');
    }

    public static function getModelLabel(): string
    {
        return __('admin.impersonation.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.impersonation.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.tenants.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false && static::canViewAny();
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            Columns\TextColumn::make('admin.name')->label(__('fields.admin'))->weight('semibold'),
            Columns\TextColumn::make('tenant.name')->label(__('fields.tenant'))->searchable(),
            Columns\TextColumn::make('impersonated.email')->label(__('fields.owner')),
            Columns\TextColumn::make('reason')->label(__('fields.reason'))->wrap()->limit(80),
            Columns\TextColumn::make('started_at')->label(__('fields.started_at'))->dateTime()->placeholder(__('admin.impersonation.not_used')),
            Columns\TextColumn::make('ended_at')->label(__('fields.ended_at'))->dateTime()->placeholder('—'),
            Columns\TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageImpersonationLogs::route('/')];
    }
}
