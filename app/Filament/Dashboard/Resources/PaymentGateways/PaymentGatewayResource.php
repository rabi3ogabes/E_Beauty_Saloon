<?php

namespace App\Filament\Dashboard\Resources\PaymentGateways;

use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Domain\Workspace\Actions\SavePaymentGateway;
use App\Domain\Workspace\Actions\TestGatewayConnection;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\PaymentGateways\Pages\CreatePaymentGateway;
use App\Filament\Dashboard\Resources\PaymentGateways\Pages\EditPaymentGateway;
use App\Filament\Dashboard\Resources\PaymentGateways\Pages\ListPaymentGateways;
use App\Filament\Dashboard\Resources\Stock\BranchStockResource;
use App\Filament\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * The salon's own payment methods. Credentials are encrypted at rest (model cast) and never
 * shown back: the form displays a mask and an empty "replace" input.
 */
class PaymentGatewayResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = TenantPaymentGateway::class;

    protected static string $viewPermission = 'payments.manage';

    protected static string $managePermission = 'payments.manage';

    protected static string $navGroup = 'settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'payment-methods';

    public static function getModelLabel(): string
    {
        return __('workspace.payments.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.payments.plural');
    }

    public static function driverOptions(): array
    {
        return collect(TenantPaymentGateway::DRIVERS)->mapWithKeys(fn ($d) => [$d => __('salon.payments.drivers.'.$d)])->all();
    }

    public static function contextOptions(): array
    {
        return collect(SavePaymentGateway::CONTEXTS)->mapWithKeys(fn ($c) => [$c => __('workspace.payments.contexts.'.$c)])->all();
    }

    public static function form(Schema $schema): Schema
    {
        $credentialFields = collect(SavePaymentGateway::DRIVER_FIELDS)->flatten()->unique()->map(fn ($field) => TextInput::make('credentials.'.$field)
            ->label(__('workspace.payments.fields.'.$field))
            ->password($secret = ! in_array($field, SavePaymentGateway::PUBLIC_FIELDS, true))->revealable($secret)
            ->autocomplete('new-password')
            ->visible(fn (Get $get) => in_array($field, SavePaymentGateway::DRIVER_FIELDS[$get('driver')] ?? [], true))
            ->required(fn (Get $get, $record) => in_array($field, ['merchant_id', 'secret_key', 'api_key', 'client_id', 'key_id', 'key_secret'], true)
                && in_array($field, SavePaymentGateway::DRIVER_FIELDS[$get('driver')] ?? [], true)
                && ! ($record && $record->driver === $get('driver') && filled($record->credential($field))))
            ->placeholder(fn ($record) => $record && filled($record->credential($field)) ? SavePaymentGateway::mask((string) $record->credential($field)) : null)
            ->helperText(fn ($record) => $record && filled($record->credential($field)) ? __('workspace.payments.replace_help') : null)
            ->dehydrated(true))->values()->all();

        return $schema->columns(3)->components([
            Grid::make(1)->columnSpan(['default' => 3, 'lg' => 2])->schema([
                Section::make(__('workspace.payments.provider'))->description(__('workspace.payments.direct_copy'))->icon(Heroicon::OutlinedShieldCheck)->columns(2)->schema([
                    Select::make('driver')->label(__('workspace.payments.driver'))->options(static::driverOptions())->required()->native(false)->live(),
                    TextInput::make('label')->label(__('workspace.payments.label_field'))->placeholder(__('workspace.payments.label_placeholder'))->maxLength(80),
                    ToggleButtons::make('mode')->label(__('workspace.payments.mode'))->inline()->default('test')->required()
                        ->options(['test' => __('workspace.payments.test'), 'live' => __('workspace.payments.live')])
                        ->colors(['test' => 'warning', 'live' => 'success'])
                        ->visible(fn (Get $get) => in_array($get('driver'), TenantPaymentGateway::ONLINE_DRIVERS, true)),
                ]),
                Section::make(__('workspace.payments.credentials'))->description(__('workspace.payments.credentials_help'))->icon(Heroicon::OutlinedLockClosed)->columns(2)
                    ->visible(fn (Get $get) => ! empty(SavePaymentGateway::DRIVER_FIELDS[$get('driver')] ?? []))
                    ->schema($credentialFields),
                Section::make(__('workspace.payments.webhook'))->description(__('workspace.payments.webhook_help'))->icon(Heroicon::OutlinedLink)
                    ->visible(fn ($record, Get $get) => $record && in_array($get('driver'), TenantPaymentGateway::ONLINE_DRIVERS, true))
                    ->schema([
                        TextInput::make('webhook_url')->hiddenLabel()->readOnly()->dehydrated(false)->copyable(copyMessage: __('salon.ui.copied'))
                            ->formatStateUsing(fn ($record) => $record?->webhookUrl()),
                    ]),
            ]),
            Grid::make(1)->columnSpan(['default' => 3, 'lg' => 1])->schema([
                Section::make(__('workspace.payments.availability'))->schema([
                    Toggle::make('is_active')->label(__('fields.is_active'))->default(true),
                    CheckboxList::make('enabled_for')->label(__('workspace.payments.enabled_for'))->options(static::contextOptions())
                        ->default(['booking', 'shop', 'pos', 'deposits'])->required(),
                    CheckboxList::make('branch_ids')->label(__('fields.branches'))->options(fn () => BranchStockResource::branchOptions())
                        ->helperText(__('workspace.payments.branches_help')),
                ]),
                Section::make(__('workspace.payments.fees'))->description(__('workspace.payments.fees_help'))->schema([
                    TextInput::make('fee_percent')->label(__('workspace.payments.fee_percent'))->numeric()->minValue(0)->maxValue(20)->step('0.01')->suffix('%')->default(0),
                    Money::input('fee_fixed')->label(__('workspace.payments.fee_fixed'))->default(0),
                ]),
                Text::make(new HtmlString('<p class="rounded-xl bg-primary-50 p-4 text-sm text-primary-900 dark:bg-primary-500/10 dark:text-primary-200">'.e(__('workspace.payments.direct_copy')).'</p>')),
            ]),
        ]);
    }

    public static function testAction(): Action
    {
        return Action::make('testConnection')->label(__('workspace.payments.test_connection'))->icon(Heroicon::OutlinedSignal)->color('gray')
            ->visible(fn (TenantPaymentGateway $record) => $record->isOnline() && static::canManage())
            ->action(function (TenantPaymentGateway $record) {
                [$ok, $message] = app(TestGatewayConnection::class)->handle($record);
                Notification::make()->{$ok ? 'success' : 'danger'}()->title($ok ? __('workspace.payments.test_ok_title') : __('workspace.payments.test_failed_title'))->body($message)->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort', fn () => static::canManage())->columns([
            TextColumn::make('driver')->label(__('workspace.payments.driver'))->weight('semibold')
                ->formatStateUsing(fn ($state) => __('salon.payments.drivers.'.$state))->description(fn ($record) => $record->label),
            TextColumn::make('mode')->label(__('workspace.payments.mode'))->badge()
                ->state(fn ($record) => $record->isOnline() ? $record->mode : 'offline')
                ->formatStateUsing(fn ($state) => __('workspace.payments.'.$state))
                ->color(fn ($state) => match ($state) {
                    'live' => 'success', 'test' => 'warning', default => 'gray'
                }),
            TextColumn::make('enabled_for')->label(__('workspace.payments.enabled_for'))->badge()->color('gray')
                ->formatStateUsing(fn ($state) => __('workspace.payments.contexts.'.$state)),
            TextColumn::make('last_test_ok')->label(__('workspace.payments.connection'))->badge()
                ->state(fn ($record) => ! $record->isOnline() ? 'na' : ($record->last_tested_at === null ? 'untested' : ($record->last_test_ok ? 'ok' : 'failed')))
                ->formatStateUsing(fn ($state) => __('workspace.payments.connection_states.'.$state))
                ->color(fn ($state) => match ($state) {
                    'ok' => 'success', 'failed' => 'danger', 'untested' => 'warning', default => 'gray'
                })
                ->description(fn ($record) => $record->last_tested_at?->diffForHumans()),
            IconColumn::make('is_active')->label(__('fields.is_active'))->boolean(),
        ])->recordActions([
            static::testAction(),
            Action::make('copyWebhook')->label(__('workspace.payments.copy_webhook'))->icon(Heroicon::OutlinedClipboard)->color('gray')
                ->visible(fn (TenantPaymentGateway $record) => $record->isOnline())
                ->action(fn (TenantPaymentGateway $record, $livewire) => $livewire->js('navigator.clipboard.writeText('.json_encode($record->webhookUrl()).')'))
                ->successNotificationTitle(__('salon.ui.copied')),
            EditAction::make(),
            DeleteAction::make(),
        ])->emptyStateIcon(Heroicon::OutlinedCreditCard)
            ->emptyStateHeading(__('workspace.payments.empty'))
            ->emptyStateDescription(__('workspace.payments.empty_body'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentGateways::route('/'),
            'create' => CreatePaymentGateway::route('/create'),
            'edit' => EditPaymentGateway::route('/{record}/edit'),
        ];
    }
}
