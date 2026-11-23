<?php

namespace App\Filament\Admin\Resources\MessagePrices;

use App\Domain\Marketing\Models\PlatformMessageChannel;
use App\Filament\Admin\Resources\MessagePrices\Pages\ManagePlatformMessageChannels;
use App\Filament\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Super admin: platform-shared WhatsApp/SMS senders and the price per message billed to salons. */
class PlatformMessageChannelResource extends Resource
{
    protected static ?string $model = PlatformMessageChannel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'message-prices';

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.growth');
    }

    public static function getNavigationLabel(): string
    {
        return __('marketing.nav.message_prices');
    }

    public static function getModelLabel(): string
    {
        return __('marketing.credits.platform_sender');
    }

    public static function getPluralModelLabel(): string
    {
        return __('marketing.nav.message_prices');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.billing.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('channel')->label(__('marketing.campaigns.channels'))->options(['whatsapp' => 'WhatsApp', 'sms' => 'SMS'])->required()->unique(ignoreRecord: true),
            Select::make('provider')->label(__('marketing.channel_settings.provider'))->options(collect(['meta', 'twilio', 'unifonic', 'http'])->mapWithKeys(fn ($p) => [$p => __('marketing.channel_settings.providers.'.$p)])),
            Money::input('price_per_message', 'QAR')->label(__('marketing.credits.price_per_message'))->required(),
            Select::make('currency')->label(__('marketing.credits.currency'))->options(['QAR' => 'QAR', 'AED' => 'AED', 'SAR' => 'SAR', 'USD' => 'USD'])->default('QAR'),
            KeyValue::make('credentials')->label(__('marketing.channel_settings.provider'))->columnSpanFull()
                ->helperText('phone_number_id, token, waba_id, app_secret, verify_token · account_sid, auth_token, from · app_sid, sender_id'),
            Toggle::make('is_active')->label(__('marketing.common.active')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('channel')->label(__('marketing.campaigns.channels'))->formatStateUsing(fn ($state) => __('marketing.channels.'.$state))->weight('semibold'),
            TextColumn::make('provider')->label(__('marketing.channel_settings.provider'))->formatStateUsing(fn ($state) => $state ? __('marketing.channel_settings.providers.'.$state) : '—'),
            TextColumn::make('price_per_message')->label(__('marketing.credits.price_per_message'))->formatStateUsing(fn ($state, $record) => money((int) $state, $record->currency))->alignEnd(),
            IconColumn::make('is_active')->label(__('marketing.common.active'))->boolean(),
        ])->recordActions([EditAction::make()->slideOver()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePlatformMessageChannels::route('/')];
    }
}
