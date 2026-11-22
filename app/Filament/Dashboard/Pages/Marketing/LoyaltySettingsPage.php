<?php

namespace App\Filament\Dashboard\Pages\Marketing;

use App\Domain\CRM\Models\Client;
use App\Domain\Loyalty\Actions\LoyaltyLedger;
use App\Domain\Marketing\Models\Referral;
use App\Domain\Marketing\Support\LoyaltySettings;
use App\Filament\Marketing\MarketingAccess;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Loyalty programme rules (stored in tenant settings "loyalty.*", read by the core LoyaltyLedger). */
class LoyaltySettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'marketing/loyalty';

    protected string $view = 'filament.marketing.loyalty-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return MarketingAccess::can(['loyalty'], 'marketing.view');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.marketing');
    }

    public static function getNavigationLabel(): string
    {
        return __('marketing.nav.loyalty');
    }

    public function getTitle(): string
    {
        return __('marketing.loyalty.settings');
    }

    public function getSubheading(): ?string
    {
        return __('marketing.loyalty.subtitle');
    }

    public function mount(): void
    {
        $t = tenant();
        $this->form->fill([
            'enabled' => LoyaltySettings::enabled(),
            'points_per_currency_unit' => $t->setting('loyalty.points_per_currency_unit', 1),
            'point_value' => ((int) $t->setting('loyalty.point_value', 5)) / 100,
            'min_redeem_points' => $t->setting('loyalty.min_redeem_points', 100),
            'tiers' => collect(LoyaltySettings::tiers())->map(fn ($tier) => [
                'key' => $tier['key'], 'min_points' => $tier['min_points'], 'points_multiplier' => $tier['points_multiplier'] ?? 1,
                'perks_en' => data_get($tier, 'perks.en'), 'perks_ar' => data_get($tier, 'perks.ar'),
            ])->all(),
            'referrer_points' => LoyaltySettings::referral('referrer_points'),
            'friend_discount' => LoyaltySettings::referral('friend_discount_bp') / 100,
            'friend_coupon_days' => LoyaltySettings::referral('friend_coupon_days'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make()->columns(4)->schema([
                Toggle::make('enabled')->label(__('marketing.loyalty.enabled'))->inline(false),
                TextInput::make('points_per_currency_unit')->label(__('marketing.loyalty.points_per_currency_unit'))->numeric()->minValue(0)->step('0.1')->required(),
                TextInput::make('point_value')->label(__('marketing.loyalty.point_value'))->numeric()->minValue(0)->step('0.01')->prefix(tenant()?->currency ?? 'QAR')->required(),
                TextInput::make('min_redeem_points')->label(__('marketing.loyalty.min_redeem_points'))->numeric()->minValue(0),
            ]),
            Section::make(__('marketing.loyalty.tiers_section'))->schema([
                Repeater::make('tiers')->hiddenLabel()->columns(5)->reorderable(false)->minItems(1)->maxItems(5)->schema([
                    Select::make('key')->label(__('marketing.loyalty.tier_key'))->options(__('marketing.loyalty.tiers'))->required()->distinct(),
                    TextInput::make('min_points')->label(__('marketing.loyalty.min_points'))->numeric()->minValue(0)->required(),
                    TextInput::make('points_multiplier')->label(__('marketing.loyalty.multiplier'))->numeric()->minValue(1)->maxValue(5)->step('0.05')->default(1),
                    TextInput::make('perks_en')->label(__('marketing.loyalty.perks_en'))->maxLength(160),
                    TextInput::make('perks_ar')->label(__('marketing.loyalty.perks_ar'))->maxLength(160)->extraInputAttributes(['dir' => 'rtl']),
                ]),
            ]),
            Section::make(__('marketing.loyalty.referral_section'))->columns(3)->schema([
                TextInput::make('referrer_points')->label(__('marketing.loyalty.referrer_points'))->numeric()->minValue(0)->required(),
                TextInput::make('friend_discount')->label(__('marketing.loyalty.friend_discount'))->numeric()->minValue(0)->maxValue(100)->suffix('%')->required(),
                TextInput::make('friend_coupon_days')->label(__('marketing.loyalty.friend_coupon_days'))->numeric()->minValue(1)->required(),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(MarketingAccess::manage(['loyalty']), 403);
        $d = $this->form->getState();
        $settings = tenant()->settings ?? [];
        data_set($settings, 'loyalty', array_merge($settings['loyalty'] ?? [], [
            'enabled' => (bool) $d['enabled'],
            'points_per_currency_unit' => (float) $d['points_per_currency_unit'],
            'point_value' => (int) round(((float) $d['point_value']) * 100),
            'min_redeem_points' => (int) ($d['min_redeem_points'] ?? 0),
            'tiers' => collect($d['tiers'] ?? [])->map(fn ($t) => [
                'key' => $t['key'], 'min_points' => (int) $t['min_points'], 'points_multiplier' => (float) ($t['points_multiplier'] ?: 1),
                'perks' => ['en' => $t['perks_en'] ?? null, 'ar' => $t['perks_ar'] ?? null],
            ])->sortBy('min_points')->values()->all(),
            'referral' => [
                'referrer_points' => (int) $d['referrer_points'],
                'friend_discount_bp' => (int) round(((float) $d['friend_discount']) * 100),
                'friend_coupon_days' => (int) $d['friend_coupon_days'],
            ],
        ]));
        tenant()->update(['settings' => $settings]);
        activity()->performedOn(tenant())->log('loyalty.settings.updated');
        Notification::make()->success()->title(__('marketing.loyalty.saved'))->send();
    }

    protected function getViewData(): array
    {
        $outstanding = (int) Client::sum('loyalty_points');

        return [
            'stats' => [
                'members' => Client::where('loyalty_points', '>', 0)->count(),
                'outstanding' => number_format($outstanding),
                'liability' => money(app(LoyaltyLedger::class)->valueOf($outstanding)),
                'referrals' => Referral::where('status', 'rewarded')->count(),
            ],
        ];
    }
}
