<?php

namespace App\Filament\Dashboard\Pages;

use App\Domain\Workspace\Actions\UpdateBusinessSettings;
use App\Filament\Dashboard\Support\SettingsPage;
use App\Support\Money\Money as MoneyValue;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Booking policies (settings.booking.*) and loyalty rules (settings.loyalty.*). */
class BusinessSettings extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'business-settings';

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('workspace.business.title');
    }

    public function getTitle(): string
    {
        return __('workspace.business.title');
    }

    public function getSubheading(): ?string
    {
        return __('workspace.business.subheading');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('settings.manage');
    }

    protected function fillData(): array
    {
        $t = tenant();
        $currency = $t->currency ?? 'QAR';

        return [
            'booking' => array_replace(['slot_interval' => 15, 'min_notice_minutes' => 60, 'max_days_ahead' => 60, 'cancellation_window_hours' => 24,
                'late_cancel_fee_percent' => 0, 'no_show_fee_percent' => 100, 'reschedule_limit' => 2, 'hold_minutes' => 10], (array) $t->setting('booking', [])),
            'loyalty' => [
                'points_per_currency_unit' => $t->setting('loyalty.points_per_currency_unit', 1),
                'point_value' => MoneyValue::of((int) $t->setting('loyalty.point_value', 5), $currency)->toDecimal(),
                'tiers' => $t->setting('loyalty.tiers') ?? [['key' => 'silver', 'min_points' => 0], ['key' => 'gold', 'min_points' => 2000], ['key' => 'black', 'min_points' => 6000]],
            ],
        ];
    }

    public function form(Schema $schema): Schema
    {
        $min = __('salon.ui.minutes');
        $currency = tenant()?->currency ?? 'QAR';

        return $schema->columns(2)->components([
            Section::make(__('workspace.business.booking'))->description(__('workspace.business.booking_help'))->icon(Heroicon::OutlinedCalendarDays)->columns(2)->columnSpanFull()->schema([
                Select::make('booking.slot_interval')->label(__('workspace.business.slot_interval'))->options([5 => "5 $min", 10 => "10 $min", 15 => "15 $min", 20 => "20 $min", 30 => "30 $min", 60 => "60 $min"])->required()->native(false),
                TextInput::make('booking.min_notice_minutes')->label(__('workspace.business.min_notice'))->helperText(__('workspace.business.min_notice_help'))->integer()->minValue(0)->suffix($min)->required(),
                TextInput::make('booking.max_days_ahead')->label(__('workspace.business.max_days_ahead'))->integer()->minValue(1)->maxValue(365)->suffix(__('workspace.common.days'))->required(),
                TextInput::make('booking.hold_minutes')->label(__('workspace.business.hold_minutes'))->helperText(__('workspace.business.hold_help'))->integer()->minValue(1)->maxValue(60)->suffix($min)->required(),
                TextInput::make('booking.cancellation_window_hours')->label(__('workspace.business.cancellation_window'))->helperText(__('workspace.business.cancellation_help'))->integer()->minValue(0)->suffix(__('workspace.common.hours'))->required(),
                TextInput::make('booking.reschedule_limit')->label(__('workspace.business.reschedule_limit'))->integer()->minValue(0)->maxValue(20)->required(),
                TextInput::make('booking.late_cancel_fee_percent')->label(__('workspace.business.late_cancel_fee'))->integer()->minValue(0)->maxValue(100)->suffix('%')->required(),
                TextInput::make('booking.no_show_fee_percent')->label(__('workspace.business.no_show_fee'))->integer()->minValue(0)->maxValue(100)->suffix('%')->required(),
            ]),
            Section::make(__('workspace.business.loyalty'))->description(__('workspace.business.loyalty_help'))->icon(Heroicon::OutlinedGift)->columns(2)->columnSpanFull()->schema([
                TextInput::make('loyalty.points_per_currency_unit')->label(__('workspace.business.points_per_unit', ['currency' => $currency]))->numeric()->minValue(0)->maxValue(100)->step('0.1')->required()->live(onBlur: true),
                TextInput::make('loyalty.point_value')->label(__('workspace.business.point_value'))->numeric()->minValue(0)->step('0.01')->prefix($currency)->required()->live(onBlur: true),
                Text::make(fn (Get $get) => __('workspace.business.loyalty_example', [
                    'points' => (int) floor(100 * (float) $get('loyalty.points_per_currency_unit')),
                    'currency' => $currency,
                    'value' => money((int) round(100 * (float) $get('loyalty.points_per_currency_unit') * ((float) $get('loyalty.point_value') * 100))),
                ]))->columnSpanFull()->color('gray'),
                Repeater::make('loyalty.tiers')->label(__('workspace.business.tiers'))->columns(2)->columnSpanFull()->reorderable(false)->defaultItems(0)
                    ->addActionLabel(__('workspace.business.add_tier'))
                    ->schema([
                        TextInput::make('key')->label(__('workspace.business.tier_name'))->required()->maxLength(20)->placeholder('gold'),
                        TextInput::make('min_points')->label(__('workspace.business.tier_min'))->integer()->minValue(0)->required(),
                    ]),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_if($this->isReadOnly() || ! static::canAccess(), 403);
        $data = $this->form->getState();
        $loyalty = $data['loyalty'] ?? [];
        $loyalty['point_value'] = MoneyValue::fromDecimal((string) ($loyalty['point_value'] ?? 0), tenant()->currency ?? 'QAR')->amount;

        app(UpdateBusinessSettings::class)->handle(tenant(), $data['booking'] ?? [], $loyalty);
        Notification::make()->success()->title(__('workspace.business.saved'))->send();
    }
}
