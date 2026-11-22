<?php

namespace App\Filament\Dashboard\Resources\Marketing\Referrals;

use App\Domain\Marketing\Models\Referral;
use App\Filament\Dashboard\Resources\Marketing\Referrals\Pages\ManageReferrals;
use App\Filament\Marketing\GatedResource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ReferralResource extends Resource
{
    use GatedResource;

    protected static ?string $model = Referral::class;

    protected static array $modules = ['loyalty'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?int $navigationSort = 32;

    public static function getModelLabel(): string
    {
        return __('marketing.referrals.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('marketing.referrals.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['referrer', 'referred', 'promotion']))
            ->columns([
                TextColumn::make('referrer.name')->label(__('marketing.referrals.referrer'))->weight('semibold')->searchable(),
                TextColumn::make('referred.name')->label(__('marketing.referrals.referred'))->searchable()->description(fn (Referral $r) => $r->promotion?->code),
                TextColumn::make('status')->label(__('marketing.common.status'))->badge()->formatStateUsing(fn ($state) => __('marketing.referrals.statuses.'.$state))
                    ->color(fn ($state) => $state === 'rewarded' ? 'success' : ($state === 'pending' ? 'warning' : 'gray')),
                TextColumn::make('points_awarded')->label(__('marketing.referrals.points'))->numeric()->alignEnd(),
                TextColumn::make('rewarded_at')->label(__('marketing.referrals.rewarded_at'))->since()->placeholder('—'),
                TextColumn::make('created_at')->label(__('marketing.common.created'))->date(),
            ])
            ->filters([SelectFilter::make('status')->label(__('marketing.common.status'))->options(__('marketing.referrals.statuses'))])
            ->emptyStateHeading(__('marketing.referrals.empty'))->emptyStateIcon('heroicon-o-user-plus');
    }

    public static function getPages(): array
    {
        return ['index' => ManageReferrals::route('/')];
    }
}
