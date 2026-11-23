<?php

namespace App\Filament\Dashboard\Resources\Marketing\Packages;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Actions\PurchasePackage;
use App\Domain\Marketing\Models\Package;
use App\Filament\Dashboard\Resources\Marketing\Packages\Pages\ManagePackages;
use App\Filament\Marketing\GatedResource;
use App\Filament\Marketing\MarketingAccess;
use App\Filament\Support\Money;
use App\Filament\Support\Translatable;
use App\Support\Exceptions\DomainException;
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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PackageResource extends Resource
{
    use GatedResource;

    protected static ?string $model = Package::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('marketing.packages.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('marketing.packages.plural');
    }

    public static function form(Schema $schema): Schema
    {
        $locale = app()->getLocale();
        $serviceType = (new Service)->getMorphClass();
        $variantType = (new ProductVariant)->getMorphClass();

        return $schema->columns(3)->components([
            Section::make()->columnSpan(2)->columns(2)->schema([
                Translatable::make('name', __('marketing.common.name')),
                Translatable::make('description', __('marketing.segments.description'), required: false, textarea: true),
                TextInput::make('slug')->label('Slug')->required()->alphaDash()->maxLength(120)->default(fn () => Str::lower(Str::random(8))),
                TextInput::make('sessions_count')->label(__('marketing.packages.sessions_count'))->numeric()->minValue(1)->default(1)->required(),
                ToggleButtons::make('pricing')->label(__('marketing.packages.pricing'))->options(__('marketing.packages.pricings'))->inline()->default('fixed')->live(),
                Money::input('price')->label(__('marketing.packages.price'))->visible(fn (Get $get) => $get('pricing') !== 'percent')->required(fn (Get $get) => $get('pricing') !== 'percent'),
                TextInput::make('discount_bp')->label(__('marketing.packages.discount'))->numeric()->minValue(0)->maxValue(100)->suffix('%')
                    ->formatStateUsing(fn ($state) => $state ? $state / 100 : null)->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                    ->visible(fn (Get $get) => $get('pricing') === 'percent'),
                Repeater::make('items')->relationship()->label(__('marketing.packages.items'))->columns(3)->columnSpanFull()->defaultItems(1)
                    ->schema([
                        Select::make('itemable_type')->label(__('marketing.packages.item_type'))->required()->live()->default($serviceType)
                            ->options([$serviceType => __('marketing.packages.item_types.service'), $variantType => __('marketing.packages.item_types.product')])
                            ->afterStateUpdated(fn (Set $set) => $set('itemable_id', null)),
                        Select::make('itemable_id')->label(__('marketing.packages.item'))->required()->searchable()
                            ->options(fn (Get $get) => $get('itemable_type') === $variantType
                                ? ProductVariant::with('product')->limit(500)->get()->mapWithKeys(fn ($v) => [$v->id => $v->product?->getTranslation('name', $locale).' · '.$v->getTranslation('name', $locale)])
                                : Service::all()->mapWithKeys(fn ($s) => [$s->id => $s->getTranslation('name', $locale)])),
                        TextInput::make('quantity')->label(__('marketing.packages.quantity'))->numeric()->minValue(1)->default(1)->required(),
                    ]),
            ]),
            Section::make()->columnSpan(1)->schema([
                TextInput::make('validity_days')->label(__('marketing.packages.validity_days'))->numeric()->minValue(1)->suffix(__('marketing.common.days')),
                Select::make('branch_ids')->label(__('marketing.common.branches'))->multiple()->placeholder(__('marketing.common.all_branches'))
                    ->options(fn () => Branch::all()->mapWithKeys(fn ($b) => [$b->id => $b->getTranslation('name', $locale)])),
                Toggle::make('sellable_online')->label(__('marketing.packages.sellable_online'))->default(true),
                Toggle::make('sellable_pos')->label(__('marketing.packages.sellable_pos'))->default(true),
                Toggle::make('is_active')->label(__('marketing.common.active'))->default(true),
                TextInput::make('sort')->label(__('marketing.common.sort'))->numeric()->default(0),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $locale = app()->getLocale();

        return $table->defaultSort('sort')->reorderable('sort')
            ->modifyQueryUsing(fn ($query) => $query->with('items.itemable')->withCount(['clientPackages' => fn ($q) => $q->where('status', '!=', 'pending')]))
            ->columns([
                TextColumn::make('name')->label(__('marketing.common.name'))->weight('semibold')->searchable()
                    ->formatStateUsing(fn (Package $record) => $record->getTranslation('name', $locale))
                    ->description(fn (Package $r) => $r->items->map(fn ($i) => ($i->quantity > 1 ? $i->quantity.'× ' : '').$i->itemable?->getTranslation('name', $locale))->join(' · ')),
                TextColumn::make('sessions_count')->label(__('marketing.packages.sessions_count'))->alignCenter(),
                TextColumn::make('effective')->label(__('marketing.packages.effective_price'))->state(fn (Package $r) => money($r->effectivePrice()))
                    ->description(fn (Package $r) => $r->savings() > 0 ? __('marketing.packages.value').': '.money($r->itemsValue()) : null)->alignEnd(),
                TextColumn::make('validity_days')->label(__('marketing.packages.validity_days'))->placeholder('∞')->alignCenter(),
                TextColumn::make('client_packages_count')->label(__('marketing.packages.sold'))->alignEnd(),
                IconColumn::make('sellable_online')->label(__('marketing.packages.sellable_online'))->boolean(),
                IconColumn::make('is_active')->label(__('marketing.common.active'))->boolean(),
            ])->recordActions([
                Action::make('sell')->label(__('marketing.packages.sell'))->icon('heroicon-o-banknotes')->color('gray')
                    ->visible(fn () => MarketingAccess::manage())
                    ->schema([Select::make('client_id')->label(__('marketing.packages.client'))->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search) => Client::where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->limit(25)->pluck('name', 'id'))])
                    ->action(function (Package $record, array $data) {
                        try {
                            app(PurchasePackage::class)->handle($record, Client::findOrFail($data['client_id']), 'dashboard');
                            Notification::make()->success()->title(__('marketing.packages.sold_ok'))->send();
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                EditAction::make()->slideOver(),
                DeleteAction::make(),
            ])->emptyStateHeading(__('marketing.packages.empty'))->emptyStateDescription(__('marketing.packages.empty_help'))->emptyStateIcon('heroicon-o-gift');
    }

    public static function getPages(): array
    {
        return ['index' => ManagePackages::route('/')];
    }
}
