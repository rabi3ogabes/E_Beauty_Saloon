<?php

namespace App\Http\Controllers\Site\Shop;

use App\Domain\Shop\Actions\MarkDelivered;
use App\Domain\Shop\Actions\MarkOutForDelivery;
use App\Domain\Shop\Enums\OrderStatus;
use App\Domain\Shop\Models\Driver;
use App\Domain\Shop\Models\Order;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use App\Support\Tenancy\BranchScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** Mobile web view for internal drivers: assigned orders, map/call/WhatsApp, "Delivered" + COD collected. */
class DriverController extends Controller
{
    public function show(Driver $driver)
    {
        abort_unless($driver->is_active, 403);

        return $this->render($driver, signed: true);
    }

    public function staff(Request $request)
    {
        $driver = Driver::where('user_id', $request->user('web')->id)->where('is_active', true)->first();
        abort_unless($driver, 403, __('shop.driver.not_a_driver'));

        return $this->render($driver, signed: false);
    }

    public function delivered(Request $request, Driver $driver, string $order)
    {
        abort_unless($driver->is_active, 403);

        return $this->complete($request, $driver, $order);
    }

    public function staffDelivered(Request $request, string $order)
    {
        $driver = Driver::where('user_id', $request->user('web')->id)->where('is_active', true)->firstOrFail();

        return $this->complete($request, $driver, $order);
    }

    protected function complete(Request $request, Driver $driver, string $number)
    {
        $data = $request->validate(['step' => ['required', 'in:out,delivered'], 'cod_method' => ['nullable', 'in:cash,card_terminal']]);
        $order = Order::withoutGlobalScope(BranchScope::class)->where('driver_id', $driver->id)->where('number', $number)->firstOrFail();

        try {
            if ($data['step'] === 'out') {
                app(MarkOutForDelivery::class)->handle($order, null, __('shop.driver.note_by', ['name' => $driver->name]));
            } else {
                app(MarkDelivered::class)->handle($order, $data['cod_method'] ?? null, __('shop.driver.note_by', ['name' => $driver->name]));
            }
        } catch (DomainException $e) {
            return back()->withErrors(['driver' => $e->getMessage()]);
        }

        return back()->with('status', $data['step'] === 'out' ? __('shop.driver.on_the_way') : __('shop.driver.done', ['number' => $order->number]));
    }

    protected function render(Driver $driver, bool $signed)
    {
        $orders = Order::withoutGlobalScope(BranchScope::class)->where('driver_id', $driver->id)
            ->where(fn ($q) => $q->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Preparing->value, OrderStatus::OutForDelivery->value])
                ->orWhere(fn ($w) => $w->where('status', OrderStatus::Delivered->value)->where('delivered_at', '>=', now()->startOfDay())))
            ->with('items')->orderByRaw("case when status = 'out_for_delivery' then 0 when status = 'delivered' then 2 else 1 end")->oldest('id')->get();

        $actionUrl = fn (Order $o) => $signed
            ? URL::signedRoute('shop.driver.delivered', ['driver' => $driver->id, 'order' => $o->number], absolute: false)
            : route('shop.driver.staff.delivered', $o->number);

        return view('site.shop.driver', compact('driver', 'orders', 'actionUrl'));
    }
}
