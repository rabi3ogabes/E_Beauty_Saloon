<?php

namespace App\Filament\Dashboard\Pages\AIStudio;

use App\Domain\AIStudio\Models\AICreditPack;
use App\Domain\AIStudio\Models\AIProvider;
use App\Domain\AIStudio\Models\AIWallet;
use App\Filament\Support\Money;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Studio settings: client free tries, price per look, retention, kiosk, wallet alerts, BYOK. */
class AIStudioSettings extends Page
{
    protected static ?string $slug = 'ai-studio/settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.dashboard.ai-studio.settings';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.ai_studio');
    }

    public static function getNavigationLabel(): string
    {
        return __('ai_studio.dashboard.settings');
    }

    public function getTitle(): string
    {
        return __('ai_studio.dashboard.settings');
    }

    public static function canAccess(): bool
    {
        return (bool) (tenant()?->hasModule('ai_studio') && auth()->user()?->can('ai_studio.manage'));
    }

    public function mount(): void
    {
        $tenant = tenant();
        $wallet = AIWallet::for($tenant);
        $own = AIProvider::where('tenant_id', $tenant->id)->get()->keyBy('driver');
        $this->form->fill([
            'free_tries_per_client' => (int) $tenant->setting('ai.free_tries_per_client', 3),
            'price_per_look' => (int) $tenant->setting('ai.price_per_look', 0),
            'retention_days' => (int) $tenant->setting('ai.retention_days', config('ai.retention_days')),
            'rate_limit_per_hour' => (int) $tenant->setting('ai.rate_limit_per_hour', config('ai.rate_limit_per_hour')),
            'kiosk_enabled' => (bool) $tenant->setting('ai.kiosk_enabled', true),
            'kiosk_key' => (string) $tenant->setting('ai.kiosk_key', ''),
            'low_balance_threshold' => $wallet->low_balance_threshold,
            'auto_topup' => $wallet->auto_topup,
            'auto_topup_pack_id' => $wallet->auto_topup_pack_id,
            'byok_enabled' => $own->contains(fn ($p) => $p->enabled),
            'byok' => ['gemini' => null, 'openai' => null, 'anthropic' => null],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(__('ai_studio.dashboard.settings'))->columns(2)->schema([
                TextInput::make('free_tries_per_client')->label(__('ai_studio.dashboard.free_tries'))->numeric()->minValue(0)->maxValue(100)->required(),
                Money::input('price_per_look')->label(__('ai_studio.dashboard.price_per_look')),
                TextInput::make('retention_days')->label(__('ai_studio.dashboard.retention_days'))->numeric()->minValue(1)->maxValue(90)->required(),
                TextInput::make('rate_limit_per_hour')->label(__('ai_studio.dashboard.rate_limit'))->numeric()->minValue(1)->maxValue(100)->required(),
                Toggle::make('kiosk_enabled')->label(__('ai_studio.dashboard.kiosk_enabled'))->inline(false),
                TextInput::make('kiosk_key')->label(__('ai_studio.dashboard.kiosk_key'))->helperText(__('ai_studio.dashboard.kiosk_key_help'))->alphaNum()->maxLength(64),
            ]),
            Section::make(__('ai_studio.dashboard.wallet'))->columns(3)->schema([
                TextInput::make('low_balance_threshold')->label(__('ai_studio.dashboard.low_threshold'))->numeric()->minValue(0)->required(),
                Toggle::make('auto_topup')->label(__('ai_studio.dashboard.auto_topup'))->inline(false)->live(),
                Select::make('auto_topup_pack_id')->label(__('ai_studio.dashboard.auto_topup_pack'))->visible(fn ($get) => $get('auto_topup'))
                    ->options(fn () => AICreditPack::active()->get()->mapWithKeys(fn ($p) => [$p->id => $p->name.' — '.number_format($p->credits)])->all()),
            ]),
            Section::make(__('ai_studio.dashboard.byok_title'))->description(__('ai_studio.dashboard.byok_help'))->columns(3)->collapsed()->schema([
                Toggle::make('byok_enabled')->label(__('ai_studio.admin.enabled'))->columnSpanFull(),
                TextInput::make('byok.gemini')->label('Google Gemini')->password()->placeholder('••••'),
                TextInput::make('byok.openai')->label('OpenAI')->password()->placeholder('••••'),
                TextInput::make('byok.anthropic')->label('Anthropic Claude')->password()->placeholder('••••'),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_if(tenant()->isReadOnly(), 423);
        $data = $this->form->getState();
        $tenant = tenant();
        $settings = $tenant->settings ?? [];
        $settings['ai'] = array_merge($settings['ai'] ?? [], [
            'free_tries_per_client' => (int) $data['free_tries_per_client'],
            'price_per_look' => (int) ($data['price_per_look'] ?? 0),
            'retention_days' => (int) $data['retention_days'],
            'rate_limit_per_hour' => (int) $data['rate_limit_per_hour'],
            'kiosk_enabled' => (bool) $data['kiosk_enabled'],
            'kiosk_key' => (string) ($data['kiosk_key'] ?? ''),
        ]);
        $tenant->update(['settings' => $settings]);

        AIWallet::for($tenant)->update([
            'low_balance_threshold' => (int) $data['low_balance_threshold'],
            'auto_topup' => (bool) $data['auto_topup'],
            'auto_topup_pack_id' => $data['auto_topup'] ? ($data['auto_topup_pack_id'] ?? null) : null,
        ]);

        foreach (($data['byok'] ?? []) as $driver => $key) {
            if (filled($key)) {
                AIProvider::updateOrCreate(['tenant_id' => $tenant->id, 'driver' => $driver], [
                    'name' => $tenant->name.' · '.$driver, 'api_key' => $key, 'priority' => 1, 'enabled' => true,
                ]);
            }
        }
        AIProvider::where('tenant_id', $tenant->id)->update(['enabled' => (bool) $data['byok_enabled']]);

        activity()->performedOn($tenant)->log('ai.settings.updated');
        Notification::make()->success()->title(__('ai_studio.dashboard.saved'))->send();
        $this->mount();
    }
}
