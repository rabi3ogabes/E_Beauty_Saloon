<?php

namespace App\Filament\Dashboard\Resources\Shop\Orders;

use App\Domain\Shop\Actions\AssignDriver;
use App\Domain\Shop\Actions\MoveOrder;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Driver;
use App\Domain\Shop\Models\Order;
use App\Filament\Dashboard\Resources\Shop\Orders\Pages\ListOrders;
use App\Filament\Dashboard\Resources\Shop\Orders\Pages\ViewOrder;
use App\Filament\Dashboard\Resources\Shop\ShopGate;
use App\Filament\Support\Money;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): ?string
    {
        return ShopGate::group();
    }

    public static function getModelLabel(): string
    {
        return __('shop.admin.orders.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('shop.admin.orders.plural');
    }

    public static function canViewAny(): bool
    {
        return ShopGate::view();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Order::where('status', OrderStatus::Pending->value)->count();

        return $pending ? (string) $pending : null;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'customer_name', 'customer_phone'];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['branch', 'driver'])->withCount('items'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('shop.admin.fields.number'))->searchable()->weight('semibold')
                    ->description(fn (Order $record) => $record->created_at->diffForHumans()),
                TextColumn::make('customer_name')->label(__('shop.admin.fields.customer'))->searchable(['customer_name', 'customer_phone'])
                    ->description(fn (Order $record) => $record->customer_phone),
                TextColumn::make('status')->label(__('shop.admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => $state->color()),
                TextColumn::make('fulfilment')->label(__('shop.admin.fields.fulfilment'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => __('shop.fulfilment.'.$state)),
                TextColumn::make('branch.name')->label(__('shop.admin.fields.branch'))->toggleable(),
                TextColumn::make('payment_method')->label(__('shop.admin.fields.payment_method'))
                    ->formatStateUsing(fn ($state) => __('shop.payment_methods.'.$state))
                    ->description(fn (Order $record) => __('shop.payment_status.'.$record->paymentStatus())),
                TextColumn::make('items_count')->label(__('shop.admin.fields.items'))->alignCenter()->toggleable(),
                TextColumn::make('driver.name')->label(__('shop.admin.fields.driver'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Money::column('total')->label(__('shop.admin.fields.total')),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('shop.admin.fields.status'))->multiple()
                    ->options(collect(OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                SelectFilter::make('fulfilment')->label(__('shop.admin.fields.fulfilment'))
                    ->options(['delivery' => __('shop.fulfilment.delivery'), 'pickup' => __('shop.fulfilment.pickup'), 'same_day' => __('shop.fulfilment.same_day')]),
                SelectFilter::make('payment_method')->label(__('shop.admin.fields.payment_method'))
                    ->options(collect(['online', 'cod', 'wallet', 'gift_card'])->mapWithKeys(fn ($m) => [$m => __('shop.payment_methods.'.$m)])),
                SelectFilter::make('branch_id')->label(__('shop.admin.fields.branch'))->relationship('branch', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    ...static::moveActions(),
                    static::documentAction('packing_slip', 'packing-slip', Heroicon::OutlinedClipboardDocumentList),
                    static::documentAction('invoice', 'invoice', Heroicon::OutlinedDocumentText),
                ]),
            ])
            ->emptyStateHeading(__('shop.admin.empty.orders'))
            ->emptyStateDescription(__('shop.admin.empty.orders_sub'))
            ->emptyStateIcon(Heroicon::OutlinedShoppingBag);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                Section::make(fn (Order $record) => $record->number)->columnSpan(2)->columns(3)->schema([
                    TextEntry::make('status')->label(__('shop.admin.fields.status'))->badge()
                        ->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => $state->color()),
                    TextEntry::make('fulfilment')->label(__('shop.admin.fields.fulfilment'))->formatStateUsing(fn ($state) => __('shop.fulfilment.'.$state)),
                    TextEntry::make('created_at')->label(__('shop.admin.fields.placed_at'))->dateTime(),
                    TextEntry::make('customer_name')->label(__('shop.admin.fields.customer')),
                    TextEntry::make('customer_phone')->label(__('shop.admin.fields.phone'))->copyable()
                        ->url(fn (Order $record) => 'https://wa.me/'.ltrim($record->customer_phone, '+'), shouldOpenInNewTab: true),
                    TextEntry::make('branch.name')->label(__('shop.admin.fields.branch')),
                    TextEntry::make('address')->label(__('shop.admin.fields.address'))->columnSpan(2)->placeholder('—')
                        ->state(fn (Order $record) => $record->address ? collect([$record->address['unit'] ?? null, $record->address['building'] ?? null, $record->address['street'] ?? null, $record->address['area'] ?? null])->filter()->implode(', ') : null)
                        ->url(fn (Order $record) => ! empty($record->address['lat']) ? 'https://www.openstreetmap.org/?mlat='.$record->address['lat'].'&mlon='.$record->address['lng'].'#map=17/'.$record->address['lat'].'/'.$record->address['lng'] : null, shouldOpenInNewTab: true),
                    TextEntry::make('zone.name')->label(__('shop.admin.fields.zone'))->placeholder('—'),
                    TextEntry::make('delivery_slot')->label(__('shop.admin.fields.slot'))->placeholder('—'),
                    TextEntry::make('ready_estimate_at')->label(__('shop.admin.fields.ready_estimate'))->dateTime()->placeholder('—'),
                    TextEntry::make('driver.name')->label(__('shop.admin.fields.driver'))->placeholder('—'),
                    TextEntry::make('gift_message')->label(__('shop.admin.fields.gift_message'))->placeholder('—')->columnSpan(2),
                    TextEntry::make('notes')->label(__('shop.admin.fields.notes'))->placeholder('—'),
                ]),
                Section::make(__('shop.admin.fields.payment_method'))->columnSpan(1)->schema([
                    TextEntry::make('payment_method')->hiddenLabel()->formatStateUsing(fn ($state, Order $record) => __('shop.payment_methods.'.$state).' · '.__('shop.payment_status.'.$record->paymentStatus())),
                    TextEntry::make('subtotal')->label(__('shop.admin.fields.subtotal'))->formatStateUsing(fn ($state) => money((int) $state))->inlineLabel(),
                    TextEntry::make('discount')->label(__('shop.admin.fields.discount'))->formatStateUsing(fn ($state, Order $record) => '−'.money((int) $state).($record->coupon_code ? ' ('.$record->coupon_code.')' : ''))->inlineLabel()->visible(fn (Order $record) => $record->discount > 0),
                    TextEntry::make('delivery_fee')->label(__('shop.admin.fields.delivery_fee'))->formatStateUsing(fn ($state) => money((int) $state))->inlineLabel(),
                    TextEntry::make('tax')->label(__('shop.admin.fields.tax'))->formatStateUsing(fn ($state) => money((int) $state))->inlineLabel()->visible(fn (Order $record) => $record->tax > 0),
                    TextEntry::make('total')->label(__('shop.admin.fields.total'))->formatStateUsing(fn ($state) => money((int) $state))->inlineLabel()->weight('semibold'),
                    TextEntry::make('paid')->label(__('shop.admin.fields.paid'))->formatStateUsing(fn ($state) => money((int) $state))->inlineLabel(),
                    TextEntry::make('refunded')->label(__('shop.admin.fields.refunded'))->formatStateUsing(fn ($state) => money((int) $state))->inlineLabel()->visible(fn (Order $record) => $record->refunded > 0),
                ]),
                Section::make(__('shop.admin.fields.items'))->columnSpan(2)->schema([
                    RepeatableEntry::make('items')->hiddenLabel()->columns(4)->schema([
                        TextEntry::make('name')->hiddenLabel()->state(fn ($record) => $record->label())->columnSpan(2),
                        TextEntry::make('qty')->hiddenLabel()->formatStateUsing(fn ($state, $record) => $state.' × '.money((int) $record->unit_price)),
                        TextEntry::make('total')->hiddenLabel()->formatStateUsing(fn ($state) => money((int) $state))->alignEnd(),
                    ]),
                ]),
                Section::make(__('shop.admin.fields.history'))->columnSpan(1)->schema([
                    RepeatableEntry::make('history')->hiddenLabel()->schema([
                        TextEntry::make('to_status')->hiddenLabel()->formatStateUsing(fn ($state) => OrderStatus::from($state)->label())->weight('semibold')
                            ->helperText(fn ($record) => trim($record->created_at?->format('Y-m-d H:i').' '.$record->user?->name.' '.$record->note)),
                    ]),
                ]),
            ]),
        ]);
    }

    /** "Move to …" actions — each runs the matching lifecycle Action through MoveOrder. */
    public static function moveActions(): array
    {
        $run = function (Order $record, OrderStatus $to, array $data = []) {
            try {
                app(MoveOrder::class)->handle($record, $to, $data);
                Notification::make()->success()->title(__('shop.admin.board.moved', ['number' => $record->number, 'status' => $to->label()]))->send();
            } catch (DomainException $e) {
                Notification::make()->danger()->title($e->getMessage())->send();
            }
        };
        $can = fn (Order $record, OrderStatus $to) => ShopGate::manage() && $record->status->canTransitionTo($to);

        return [
            Action::make('confirm')->label(__('shop.admin.actions.confirm'))->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (Order $record) => $can($record, OrderStatus::Confirmed))->action(fn (Order $record) => $run($record, OrderStatus::Confirmed)),
            Action::make('preparing')->label(__('shop.admin.actions.preparing'))->icon(Heroicon::OutlinedArchiveBox)
                ->visible(fn (Order $record) => $can($record, OrderStatus::Preparing) && $record->status !== OrderStatus::OutForDelivery)->action(fn (Order $record) => $run($record, OrderStatus::Preparing)),
            Action::make('ready')->label(__('shop.admin.actions.ready'))->icon(Heroicon::OutlinedBuildingStorefront)
                ->visible(fn (Order $record) => $record->isPickup() && $can($record, OrderStatus::ReadyForPickup))->action(fn (Order $record) => $run($record, OrderStatus::ReadyForPickup)),
            Action::make('out')->label(__('shop.admin.actions.out'))->icon(Heroicon::OutlinedTruck)
                ->visible(fn (Order $record) => $record->isDelivery() && $can($record, OrderStatus::OutForDelivery))
                ->fillForm(fn (Order $record) => ['driver_id' => $record->driver_id])
                ->schema([Select::make('driver_id')->label(__('shop.admin.fields.driver'))->options(fn () => Driver::active()->pluck('name', 'id'))])
                ->action(fn (Order $record, array $data) => $run($record, OrderStatus::OutForDelivery, $data)),
            Action::make('delivered')->label(__('shop.admin.actions.delivered'))->icon(Heroicon::OutlinedHandThumbUp)->color('success')
                ->visible(fn (Order $record) => $can($record, OrderStatus::Delivered))
                ->schema(fn (Order $record) => $record->balanceDue() > 0 ? [Select::make('cod_method')->label(__('shop.admin.fields.cod_method'))->default($record->cod_method ?? 'cash')->required()
                    ->options(['cash' => __('shop.payment_methods.cash'), 'card_terminal' => __('shop.payment_methods.card_terminal')])] : [])
                ->action(fn (Order $record, array $data) => $run($record, OrderStatus::Delivered, $data)),
            Action::make('assignDriver')->label(__('shop.admin.actions.assign_driver'))->icon(Heroicon::OutlinedUser)->color('gray')
                ->visible(fn (Order $record) => ShopGate::manage() && $record->isDelivery() && ! $record->status->isFinal())
                ->fillForm(fn (Order $record) => ['driver_id' => $record->driver_id])
                ->schema([Select::make('driver_id')->label(__('shop.admin.fields.driver'))->options(fn () => Driver::active()->pluck('name', 'id'))->required()])
                ->action(function (Order $record, array $data) {
                    try {
                        app(AssignDriver::class)->handle($record, Driver::findOrFail($data['driver_id']));
                        Notification::make()->success()->title(__('shop.admin.actions.done'))->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('cancel')->label(__('shop.admin.actions.cancel'))->icon(Heroicon::OutlinedXCircle)->color('danger')->requiresConfirmation()
                ->visible(fn (Order $record) => $can($record, OrderStatus::Cancelled))
                ->schema([
                    Select::make('reason')->label(__('shop.admin.fields.reason'))->required()->default('salon')
                        ->options(collect(['client', 'salon', 'out_of_stock', 'other'])->mapWithKeys(fn ($r) => [$r => __('shop.cancel_reasons.'.$r)])),
                    Toggle::make('store_credit')->label(__('shop.admin.fields.store_credit')),
                ])
                ->action(fn (Order $record, array $data) => $run($record, OrderStatus::Cancelled, $data)),
            Action::make('return')->label(__('shop.admin.actions.return'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')->requiresConfirmation()
                ->visible(fn (Order $record) => $can($record, OrderStatus::Returned))
                ->schema([
                    Toggle::make('restock')->label(__('shop.admin.fields.restock'))->default(true),
                    Toggle::make('store_credit')->label(__('shop.admin.fields.store_credit')),
                    Textarea::make('note')->label(__('shop.admin.fields.note'))->rows(2),
                ])
                ->action(fn (Order $record, array $data) => $run($record, OrderStatus::Returned, $data)),
        ];
    }

    public static function documentAction(string $name, string $doc, $icon): ActionGroup
    {
        return ActionGroup::make([
            Action::make($name.'_en')->label(__('shop.admin.actions.english'))->url(fn (Order $record) => route('shop.admin.'.$doc, ['order' => $record->number, 'lang' => 'en']), shouldOpenInNewTab: true),
            Action::make($name.'_ar')->label(__('shop.admin.actions.arabic'))->url(fn (Order $record) => route('shop.admin.'.$doc, ['order' => $record->number, 'lang' => 'ar']), shouldOpenInNewTab: true),
        ])->label(__('shop.admin.actions.'.$name))->icon($icon)->dropdownPlacement('left-start');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
