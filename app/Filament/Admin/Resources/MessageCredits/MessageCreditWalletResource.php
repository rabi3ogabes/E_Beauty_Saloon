<?php

namespace App\Filament\Admin\Resources\MessageCredits;

use App\Domain\Marketing\Models\MessageCreditWallet;
use App\Domain\Marketing\Services\MessageCredits;
use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Admin\Resources\MessageCredits\Pages\ManageMessageCreditWallets;
use App\Filament\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Super admin: salons' message credit wallets (platform-shared WhatsApp/SMS) and top-ups. */
class MessageCreditWalletResource extends Resource
{
    protected static ?string $model = MessageCreditWallet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?int $navigationSort = 41;

    protected static ?string $slug = 'message-credits';

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.growth');
    }

    public static function getNavigationLabel(): string
    {
        return __('marketing.nav.message_credits');
    }

    public static function getModelLabel(): string
    {
        return __('marketing.channel_settings.wallet');
    }

    public static function getPluralModelLabel(): string
    {
        return __('marketing.nav.message_credits');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.billing.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function topUpAction(string $name = 'topup'): Action
    {
        return Action::make($name)->label(__('marketing.credits.top_up'))->icon('heroicon-o-plus-circle')
            ->schema(fn (?MessageCreditWallet $record) => array_filter([
                $record ? null : Select::make('tenant_id')->label(__('marketing.credits.tenant'))->required()->searchable()
                    ->options(fn () => Tenant::orderBy('name')->pluck('name', 'id')),
                Money::input('amount', 'QAR')->label(__('marketing.credits.amount'))->required(),
                TextInput::make('note')->label(__('marketing.credits.note'))->maxLength(190),
            ]))
            ->action(function (array $data, ?MessageCreditWallet $record) {
                $tenantId = $record?->tenant_id ?? (int) $data['tenant_id'];
                app(MessageCredits::class)->topUp($tenantId, (int) $data['amount'], $data['note'] ?? null, 'topup:'.Str::ulid());
                activity()->withProperties(['tenant_id' => $tenantId, 'amount' => (int) $data['amount']])->log('message_credits.topped_up');
                Notification::make()->success()->title(__('marketing.credits.topped_up'))->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('balance')
            ->modifyQueryUsing(fn ($query) => $query->with('tenant'))
            ->columns([
                TextColumn::make('tenant.name')->label(__('marketing.credits.tenant'))->weight('semibold')->searchable(),
                TextColumn::make('balance')->label(__('marketing.channel_settings.balance'))->formatStateUsing(fn ($state, $record) => money((int) $state, $record->currency))->alignEnd()->sortable()
                    ->color(fn ($state, $record) => $state < $record->low_balance_threshold ? 'danger' : null),
                TextColumn::make('updated_at')->label(__('marketing.common.created'))->since(),
            ])->recordActions([static::topUpAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMessageCreditWallets::route('/')];
    }
}
