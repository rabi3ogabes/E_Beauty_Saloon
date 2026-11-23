<?php

namespace App\Http\Controllers\Site\Shop;

use App\Domain\CRM\Models\Client;
use App\Domain\Shop\Models\Order;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\BranchScope;
use Illuminate\Http\Request;

/** "Orders" section of My Account (registered through AccountSections). */
class AccountOrdersController extends Controller
{
    public function render(Client $client, Request $request): string
    {
        $base = Order::withoutGlobalScope(BranchScope::class)->where('client_id', $client->id);

        if ($number = $request->query('order')) {
            $order = (clone $base)->where('number', $number)->with(['items', 'history', 'branch' => fn ($q) => $q->withoutGlobalScopes()])->firstOrFail();

            return view('site.shop.account.order', compact('order'))->render();
        }

        return view('site.shop.account.orders', [
            'orders' => $base->withCount('items')->latest('id')->limit(50)->get(),
        ])->render();
    }
}
