<?php

namespace App\Filament\Dashboard\Pages\Marketing;

use App\Domain\Marketing\Models\MessageCreditTransaction;
use App\Domain\Marketing\Models\PlatformMessageChannel;
use App\Domain\Marketing\Models\TenantChannelSetting;
use App\Domain\Marketing\Services\MessageCredits;
use App\Filament\Marketing\MarketingAccess;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Own WhatsApp/SMS/email/push credentials or platform-shared mode, message credits, quiet hours. */
class ChannelSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'marketing/channels';

    protected string $view = 'filament.marketing.channel-settings';

    public ?array $data = [];

    /** Credential keys per provider (secrets are never echoed back to the browser). */
    public const FIELDS = [
        'meta' => ['phone_number_id', 'waba_id', 'token', 'app_secret', 'verify_token'],
        'twilio' => ['account_sid', 'auth_token', 'from', 'inbound_secret'],
        'unifonic' => ['app_sid', 'sender_id', 'inbound_secret'],
        'http' => ['url', 'method', 'format', 'params', 'headers', 'from', 'success_contains', 'inbound_secret'],
        'mail' => ['from_address', 'from_name', 'reply_to'],
        'ses' => ['from_address', 'from_name', 'reply_to'],
        'fcm' => ['project_id', 'service_account'],
    ];

    public const SECRETS = ['token', 'app_secret', 'auth_token', 'app_sid', 'service_account'];

    public static function canAccess(): bool
    {
        return MarketingAccess::can(['marketing'], 'marketing.manage');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.marketing');
    }

    public static function getNavigationLabel(): string
    {
        return __('marketing.nav.channels');
    }

    public function getTitle(): string
    {
        return __('marketing.channel_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('marketing.channel_settings.subtitle');
    }

    public function mount(): void
    {
        $settings = TenantChannelSetting::all()->keyBy('channel');
        $data = ['channels' => []];
        foreach (['whatsapp', 'sms', 'email', 'push'] as $channel) {
            $s = $settings[$channel] ?? null;
            $creds = collect($s?->credentials ?? [])->map(fn ($v, $k) => in_array($k, self::SECRETS, true) ? null : (is_array($v) ? json_encode($v) : $v))->all();
            $data['channels'][$channel] = array_merge($creds, [
                'is_active' => $s?->is_active ?? false,
                'mode' => $s?->mode ?? 'own',
                'provider' => $s?->provider ?? TenantChannelSetting::PROVIDERS[$channel][0],
            ]);
        }
        $quiet = tenant()->setting('marketing.quiet_hours') ?? [];
        $data['quiet'] = ['enabled' => $quiet['enabled'] ?? true, 'start' => $quiet['start'] ?? '21:00', 'end' => $quiet['end'] ?? '09:00'];
        $data['rate_per_minute'] = tenant()->setting('marketing.rate_per_minute', 600);
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        $tabs = [];
        foreach (['whatsapp', 'sms', 'email', 'push'] as $channel) {
            $tabs[] = Tab::make(__('marketing.channels.'.$channel))->schema($this->channelSchema($channel));
        }

        return $schema->statePath('data')->components([
            Tabs::make('channels')->tabs($tabs)->persistTabInQueryString(),
            Section::make(__('marketing.channel_settings.quiet_hours'))->description(__('marketing.channel_settings.quiet_hours_help'))->columns(4)->schema([
                Toggle::make('quiet.enabled')->label(__('marketing.channel_settings.quiet_enabled'))->inline(false),
                TimePicker::make('quiet.start')->label(__('marketing.channel_settings.quiet_start'))->seconds(false),
                TimePicker::make('quiet.end')->label(__('marketing.channel_settings.quiet_end'))->seconds(false),
                TextInput::make('rate_per_minute')->label(__('marketing.channel_settings.rate'))->numeric()->minValue(10)->maxValue(6000),
            ]),
        ]);
    }

    protected function channelSchema(string $channel): array
    {
        $p = "channels.$channel";
        $providerIs = fn (string ...$providers) => fn (Get $get) => $get("$p.mode") === 'own' && in_array($get("$p.provider"), $providers, true);
        $field = function (string $key, ...$providers) use ($p, $providerIs) {
            $label = __('marketing.channel_settings.fields.'.$key);
            $component = match ($key) {
                'params', 'headers', 'service_account' => Textarea::make("$p.$key")->rows(4),
                'method' => Select::make("$p.$key")->options(['post' => 'POST', 'get' => 'GET'])->default('post'),
                'format' => Select::make("$p.$key")->options(['form' => 'Form', 'json' => 'JSON', 'query' => 'Query string'])->default('form'),
                default => TextInput::make("$p.$key"),
            };
            if (in_array($key, self::SECRETS, true)) {
                $component = $component instanceof TextInput ? $component->password()->revealable() : $component;
                $component->placeholder('••••••••')->helperText(__('marketing.channel_settings.fields.'.$key));
            }

            return $component->label($label)->visible($providerIs(...$providers));
        };

        $schema = [
            Grid::make(3)->schema([
                Toggle::make("$p.is_active")->label(__('marketing.channel_settings.enabled'))->inline(false),
                ToggleButtons::make("$p.mode")->label(__('marketing.channel_settings.mode'))->inline()->live()
                    ->options(in_array($channel, ['whatsapp', 'sms'], true) ? __('marketing.channel_settings.modes') : ['own' => __('marketing.channel_settings.modes.own')]),
                Select::make("$p.provider")->label(__('marketing.channel_settings.provider'))->live()
                    ->options(collect(TenantChannelSetting::PROVIDERS[$channel])->mapWithKeys(fn ($pr) => [$pr => __('marketing.channel_settings.providers.'.$pr)]))
                    ->visible(fn (Get $get) => $get("$p.mode") === 'own'),
            ]),
        ];
        $grid = [];
        foreach (collect(TenantChannelSetting::PROVIDERS[$channel])->flatMap(fn ($pr) => collect(self::FIELDS[$pr])->map(fn ($k) => [$k, $pr]))->groupBy(0) as $key => $rows) {
            $grid[] = $field($key, ...$rows->pluck(1)->all());
        }
        $schema[] = Grid::make(2)->schema($grid);
        if (in_array($channel, ['whatsapp', 'sms'], true)) {
            $price = PlatformMessageChannel::priceFor($channel);
            $schema[] = Text::make(fn (Get $get) => $get("$p.mode") === 'platform_shared'
                ? __('marketing.channel_settings.price').': '.money($price) : null);
        }
        if ($channel === 'whatsapp') {
            $schema[] = Text::make(__('marketing.channel_settings.webhook_hint', ['url' => 'https://'.(config('salon.central_domains')[0] ?? 'localhost').'/webhooks/whatsapp']));
        }
        if ($channel === 'sms') {
            $schema[] = Text::make(fn (Get $get) => filled($get("$p.inbound_secret")) ? __('marketing.channel_settings.inbound_hint', ['url' => url('/webhooks/marketing/sms/'.$get("$p.inbound_secret"))]) : null);
        }

        return $schema;
    }

    public function save(): void
    {
        abort_if(tenant()->isReadOnly(), 423);
        $data = $this->form->getState();
        foreach ($data['channels'] ?? [] as $channel => $values) {
            $setting = TenantChannelSetting::firstOrNew(['channel' => $channel]);
            $provider = $values['provider'] ?? TenantChannelSetting::PROVIDERS[$channel][0];
            $creds = $setting->credentials ?? [];
            foreach (self::FIELDS[$provider] ?? [] as $key) {
                $value = $values[$key] ?? null;
                if (in_array($key, self::SECRETS, true) && blank($value)) {
                    continue; // keep the stored secret
                }
                $creds[$key] = $value;
            }
            $setting->fill([
                'mode' => in_array($channel, ['whatsapp', 'sms'], true) ? ($values['mode'] ?? 'own') : 'own',
                'provider' => $provider, 'credentials' => $creds, 'is_active' => (bool) ($values['is_active'] ?? false),
            ])->save();
        }
        $settings = tenant()->settings ?? [];
        data_set($settings, 'marketing.quiet_hours', ['enabled' => (bool) ($data['quiet']['enabled'] ?? true), 'start' => substr((string) ($data['quiet']['start'] ?? '21:00'), 0, 5), 'end' => substr((string) ($data['quiet']['end'] ?? '09:00'), 0, 5)]);
        data_set($settings, 'marketing.rate_per_minute', (int) ($data['rate_per_minute'] ?? 600));
        tenant()->update(['settings' => $settings]);
        activity()->performedOn(tenant())->log('marketing.channels.updated');

        Notification::make()->success()->title(__('marketing.channel_settings.saved'))->send();
    }

    protected function getViewData(): array
    {
        $credits = app(MessageCredits::class);

        return [
            'balance' => $credits->balance(tenant()->id),
            'transactions' => MessageCreditTransaction::where('tenant_id', tenant()->id)->latest('id')->limit(8)->get(),
            'prices' => PlatformMessageChannel::whereIn('channel', ['whatsapp', 'sms'])->pluck('price_per_message', 'channel'),
        ];
    }
}
