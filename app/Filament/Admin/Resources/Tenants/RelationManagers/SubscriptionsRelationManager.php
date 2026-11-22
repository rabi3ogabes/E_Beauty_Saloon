<?php

namespace App\Filament\Admin\Resources\Tenants\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.tenant.subscriptions');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('plan.name')->label(__('fields.plan'))->weight('semibold'),
                TextColumn::make('status')->label(__('fields.status'))->badge(),
                TextColumn::make('cycle_months')->label(__('fields.cycle'))->formatStateUsing(fn ($state) => __('admin.cycle.'.$state)),
                TextColumn::make('branches_count')->label(__('fields.branches'))->alignCenter(),
                TextColumn::make('modules')->label(__('fields.modules'))->badge()->formatStateUsing(fn ($state) => __('salon.modules.'.$state))->wrap(),
                TextColumn::make('starts_at')->label(__('fields.starts_at'))->date(),
                TextColumn::make('ends_at')->label(__('fields.ends_at'))->date(),
            ]);
    }
}
