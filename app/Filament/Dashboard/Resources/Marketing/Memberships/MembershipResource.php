<?php

namespace App\Filament\Dashboard\Resources\Marketing\Memberships;

use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Actions\EnrollMembership;
use App\Domain\Marketing\Models\Membership;
use App\Filament\Dashboard\Resources\Marketing\Memberships\Pages\ManageMemberships;
use App\Filament\Marketing\GatedResource;
use App\Filament\Marketing\MarketingAccess;
use App\Filament\Support\Money;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MembershipResource extends Resource
{
    use GatedResource;

    protected static ?string $model = Membership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 22;

    public static function getModelLabel(): string
    {
        return __('marketing.memberships.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('marketing.memberships.plural');
    }

    protected static function percentField(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->numeric()->minValue(0)->maxValue(100)->suffix('%')
            ->formatStateUsing(fn ($state) => $state ? $state / 100 : null)->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100));
    }

    public static function form(Schema $schema): Schema
    {
        $locale = app()->getLocale();

        return $schema->columns(2)->components([
            Translatable::make('name', __('marketing.common.name')),
            Translatable::make('description', __('marketing.segments.description'), required: false, textarea: true),
            Money::input('monthly_price')->label(__('marketing.memberships.monthly_price'))->required(),
            ToggleButtons::make('billing')->label(__('marketing.memberships.billing'))->options(__('marketing.memberships.billings'))->inline()->default('prepaid')->live(),
            TextInput::make('term_months')->label(__('marketing.memberships.term_months'))->numeric()->minValue(1)->default(3)->visible(fn (Get $get) => $get('billing') === 'prepaid'),
            Section::make(__('marketing.memberships.perks'))->columnSpanFull()->columns(2)->schema([
                Repeater::make('perks.included')->label(__('marketing.memberships.included'))->columns(2)->defaultItems(0)->columnSpanFull()->schema([
                    Select::make('service_id')->label(__('marketing.memberships.service'))->required()->searchable()
                        ->options(fn () => Service::all()->mapWithKeys(fn ($s) => [$s->id => $s->getTranslation('name', $locale)])),
                    TextInput::make('quantity')->label(__('marketing.memberships.quantity'))->numeric()->minValue(1)->default(1)->required(),
                ]),
                static::percentField('perks.product_discount_bp', __('marketing.memberships.product_discount')),
                static::percentField('perks.service_discount_bp', __('marketing.memberships.service_discount')),
            ]),
            Toggle::make('sellable_online')->label(__('marketing.packages.sellable_online'))->default(true),
            Toggle::make('is_active')->label(__('marketing.common.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $locale = app()->getLocale();

        return $table->defaultSort('sort')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['clientMemberships' => fn ($q) => $q->where('status', 'active')]))
            ->columns([
                TextColumn::make('name')->label(__('marketing.common.name'))->weight('semibold')->formatStateUsing(fn (Membership $record) => $record->getTranslation('name', $locale)),
                TextColumn::make('monthly_price')->label(__('marketing.memberships.monthly_price'))->formatStateUsing(fn ($state) => money((int) $state))->alignEnd(),
                TextColumn::make('billing')->label(__('marketing.memberships.billing'))->badge()->formatStateUsing(fn ($state) => __('marketing.memberships.billings.'.$state)),
                TextColumn::make('client_memberships_count')->label(__('marketing.memberships.members'))->alignEnd(),
                IconColumn::make('is_active')->label(__('marketing.common.active'))->boolean(),
            ])->recordActions([
                Action::make('enroll')->label(__('marketing.memberships.enroll'))->icon('heroicon-o-user-plus')->color('gray')->visible(fn () => MarketingAccess::manage())
                    ->schema([Select::make('client_id')->label(__('marketing.packages.client'))->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search) => Client::where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->limit(25)->pluck('name', 'id'))])
                    ->action(function (Membership $record, array $data) {
                        app(EnrollMembership::class)->handle($record, Client::findOrFail($data['client_id']));
                        Notification::make()->success()->title(__('marketing.memberships.enrolled'))->send();
                    }),
                EditAction::make()->slideOver(),
                DeleteAction::make(),
            ])->emptyStateHeading(__('marketing.memberships.empty'))->emptyStateDescription(__('marketing.memberships.empty_help'))->emptyStateIcon('heroicon-o-sparkles');
    }

    public static function getPages(): array
    {
        return ['index' => ManageMemberships::route('/')];
    }
}
