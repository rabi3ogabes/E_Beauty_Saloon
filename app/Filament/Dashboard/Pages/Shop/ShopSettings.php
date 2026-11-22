<?php

namespace App\Filament\Dashboard\Pages\Shop;

use App\Domain\Shop\Support\ShopSettings as Settings;
use App\Filament\Dashboard\Resources\Shop\ShopGate;
use App\Filament\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** COD, gift wrap, same-day cutoff, pickup time, low-stock hiding … stored in tenant settings "shop". */
class ShopSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'shop/settings';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return ShopGate::group();
    }

    public static function getNavigationLabel(): string
    {
        return __('shop.admin.settings.title');
    }

    public function getTitle(): string
    {
        return __('shop.admin.settings.title');
    }

    public static function canAccess(): bool
    {
        return ShopGate::manage();
    }

    public function mount(): void
    {
        $this->form->fill(Settings::all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(2)->components([
            Section::make(__('shop.admin.settings.cod'))->columnSpan(1)->schema([
                Toggle::make('cod_enabled')->label(__('shop.admin.settings.cod_enabled'))->live(),
                Money::input('cod_limit')->label(__('shop.admin.settings.cod_limit'))->helperText(__('shop.admin.settings.cod_limit_help'))->visible(fn (Get $get) => $get('cod_enabled')),
                Toggle::make('cod_otp_required')->label(__('shop.admin.settings.cod_otp_required'))->visible(fn (Get $get) => $get('cod_enabled')),
                TextInput::make('reservation_minutes')->label(__('shop.admin.settings.reservation_minutes'))->numeric()->minValue(5)->maxValue(1440)->required(),
            ]),
            Section::make(__('shop.admin.settings.fulfilment'))->columnSpan(1)->schema([
                TimePicker::make('same_day_cutoff')->label(__('shop.admin.settings.same_day_cutoff'))->seconds(false)->required(),
                TextInput::make('pickup_prep_minutes')->label(__('shop.admin.settings.pickup_prep_minutes'))->numeric()->minValue(0)->maxValue(10080)->required(),
                Toggle::make('gift_wrap_enabled')->label(__('shop.admin.settings.gift_wrap_enabled'))->live(),
                Money::input('gift_wrap_price')->label(__('shop.admin.settings.gift_wrap_price'))->visible(fn (Get $get) => $get('gift_wrap_enabled')),
            ]),
            Section::make(__('shop.admin.settings.storefront'))->columnSpanFull()->columns(3)->schema([
                Toggle::make('hide_out_of_stock')->label(__('shop.admin.settings.hide_out_of_stock')),
                TextInput::make('low_stock_badge')->label(__('shop.admin.settings.low_stock_badge'))->numeric()->minValue(0)->maxValue(100)->required(),
                TextInput::make('abandoned_after_minutes')->label(__('shop.admin.settings.abandoned_after_minutes'))->numeric()->minValue(15)->maxValue(10080)->required(),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')->footer([
                Actions::make([Action::make('save')->label(__('salon.ui.save'))->submit('save')->keyBindings(['mod+s'])]),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        $data = $this->form->getState();
        $data['same_day_cutoff'] = substr((string) $data['same_day_cutoff'], 0, 5);
        foreach (['cod_limit', 'gift_wrap_price'] as $money) {
            $data[$money] = (int) ($data[$money] ?? Settings::get($money));
        }
        foreach (['pickup_prep_minutes', 'low_stock_badge', 'reservation_minutes', 'abandoned_after_minutes'] as $int) {
            $data[$int] = (int) $data[$int];
        }
        Settings::save($data);
        activity()->performedOn(tenant())->withProperties($data)->log('shop.settings_updated');

        Notification::make()->success()->title(__('shop.admin.settings.saved'))->send();
    }
}
