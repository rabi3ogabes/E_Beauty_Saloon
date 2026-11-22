<?php

namespace App\Filament\Dashboard\Resources\Marketing\GiftCardOrders;

use App\Domain\Marketing\Actions\DeliverGiftCard;
use App\Domain\Marketing\Models\GiftCardOrder;
use App\Filament\Dashboard\Resources\Marketing\GiftCardOrders\Pages\ManageGiftCardOrders;
use App\Filament\Marketing\GatedResource;
use App\Filament\Marketing\MarketingAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class GiftCardOrderResource extends Resource
{
    use GatedResource;

    protected static ?string $model = GiftCardOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 24;

    public static function getModelLabel(): string
    {
        return __('marketing.gift_cards.orders');
    }

    public static function getPluralModelLabel(): string
    {
        return __('marketing.gift_cards.orders');
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
            ->modifyQueryUsing(fn ($query) => $query->with('giftCard'))
            ->columns([
                TextColumn::make('giftCard.recipient_name')->label(__('marketing.gift_cards.recipient'))->weight('semibold')->description(fn (GiftCardOrder $r) => $r->giftCard?->code),
                TextColumn::make('purchaser_name')->label(__('marketing.gift_cards.purchaser'))->searchable()->description(fn (GiftCardOrder $r) => $r->purchaser_phone ?? $r->purchaser_email),
                TextColumn::make('amount')->label(__('marketing.gift_cards.amount'))->formatStateUsing(fn ($state, GiftCardOrder $r) => money((int) $state, $r->currency))->alignEnd(),
                TextColumn::make('design')->label(__('marketing.banners.template'))->formatStateUsing(fn ($state) => __('marketing.gift_cards.designs.'.$state)),
                TextColumn::make('status')->label(__('marketing.common.status'))->badge()->formatStateUsing(fn ($state) => __('marketing.gift_cards.order_statuses.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success', 'pending' => 'warning', default => 'danger'
                    }),
                TextColumn::make('delivered_at')->label(__('marketing.gift_cards.delivered'))->since()->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->label(__('marketing.common.status'))->options(__('marketing.gift_cards.order_statuses'))])
            ->recordActions([
                Action::make('resend')->label(__('marketing.gift_cards.resend'))->icon('heroicon-o-paper-airplane')->color('gray')
                    ->visible(fn (GiftCardOrder $record) => $record->status === 'paid' && MarketingAccess::manage())
                    ->requiresConfirmation()
                    ->action(function (GiftCardOrder $record) {
                        $record->update(['delivered_at' => null]);
                        app(DeliverGiftCard::class)->handle($record->fresh());
                        Notification::make()->success()->title(__('marketing.gift_cards.resent'))->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageGiftCardOrders::route('/')];
    }
}
