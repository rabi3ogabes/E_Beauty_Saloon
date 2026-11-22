<?php

namespace App\Filament\Admin\Resources\Tenants\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.tenant.team');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->searchable(),
                TextColumn::make('email')->label(__('fields.email'))->copyable()->searchable(),
                TextColumn::make('phone')->label(__('fields.phone'))->placeholder('—'),
                IconColumn::make('is_active')->label(__('fields.is_active'))->boolean(),
                TextColumn::make('last_login_at')->label(__('admin.last_login'))->since()->placeholder(__('admin.never')),
            ]);
    }
}
