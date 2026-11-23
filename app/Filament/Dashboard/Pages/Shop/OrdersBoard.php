<?php

namespace App\Filament\Dashboard\Pages\Shop;

use App\Domain\Branches\Models\Branch;
use App\Domain\Shop\Actions\MoveOrder;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Order;
use App\Filament\Dashboard\Resources\Shop\Orders\OrderResource;
use App\Filament\Dashboard\Resources\Shop\ShopGate;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Kanban board of shop orders by status. Dropping a card on another column runs the matching
 * lifecycle Action (ConfirmOrder, MarkPreparing, …) with its validation; invalid moves bounce back.
 */
class OrdersBoard extends Page
{
    protected string $view = 'filament.dashboard.shop.orders-board';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'shop/board';

    public ?int $branch = null;

    public string $search = '';

    public static function getNavigationGroup(): ?string
    {
        return ShopGate::group();
    }

    public static function getNavigationLabel(): string
    {
        return __('shop.admin.board.title');
    }

    public function getTitle(): string
    {
        return __('shop.admin.board.title');
    }

    public function getSubheading(): ?string
    {
        return __('shop.admin.board.drag_hint');
    }

    public static function canAccess(): bool
    {
        return ShopGate::view();
    }

    /** @return list<OrderStatus> */
    public function statuses(): array
    {
        return OrderStatus::cases();
    }

    /** @return Collection<string, Collection<int, Order>> */
    public function columns(): Collection
    {
        $orders = Order::query()
            ->with(['driver'])->withCount('items')
            ->when($this->branch, fn ($q) => $q->where('branch_id', $this->branch))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', '%'.trim($this->search).'%')
                ->orWhere('customer_name', 'like', '%'.trim($this->search).'%')->orWhere('customer_phone', 'like', '%'.trim($this->search).'%')))
            ->where(fn ($q) => $q->whereIn('status', OrderStatus::open())->orWhere('updated_at', '>=', now()->subDays(14)))
            ->orderBy('created_at')
            ->limit(400)
            ->get()
            ->groupBy(fn (Order $o) => $o->status->value);

        return collect($this->statuses())->mapWithKeys(fn (OrderStatus $s) => [$s->value => $orders->get($s->value, collect())]);
    }

    public function branches(): Collection
    {
        return Branch::orderBy('sort')->get();
    }

    public function moveOrder(int $orderId, string $to): void
    {
        if (! ShopGate::manage()) {
            Notification::make()->danger()->title(__('salon.errors.module_disabled'))->send();

            return;
        }
        $order = Order::findOrFail($orderId);
        $status = OrderStatus::tryFrom($to);

        try {
            app(MoveOrder::class)->handle($order, $to, [
                'cod_method' => $order->cod_method ?? 'cash',
                'reason' => 'salon',
            ]);
            Notification::make()->success()->title(__('shop.admin.board.moved', ['number' => $order->number, 'status' => $status?->label() ?? $to]))->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function orderUrl(Order $order): string
    {
        return OrderResource::getUrl('view', ['record' => $order]);
    }
}
