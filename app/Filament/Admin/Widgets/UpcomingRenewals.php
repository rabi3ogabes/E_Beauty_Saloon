<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\Billing\Models\Subscription;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class UpcomingRenewals extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.dashboard.renewals'))
            ->query(fn () => Subscription::query()->with(['tenant', 'plan'])->whereIn('status', ['active', 'trialing'])
                ->whereBetween('ends_at', [now(), now()->addDays(14)])->orderBy('ends_at'))
            ->paginated([5, 10])
            ->columns([
                TextColumn::make('tenant.name')->label(__('fields.tenant'))->weight('semibold'),
                TextColumn::make('plan.name')->label(__('fields.plan'))->badge()->color('gray'),
                TextColumn::make('status')->label(__('fields.status'))->badge(),
                TextColumn::make('ends_at')->label(__('fields.ends_at'))->date()->description(fn ($record) => $record->ends_at->diffForHumans()),
                TextColumn::make('price_snapshot.total')->label(__('fields.total'))->formatStateUsing(fn ($state) => money((int) $state))->alignEnd(),
            ])
            ->emptyStateHeading(__('admin.dashboard.no_renewals'));
    }
}
