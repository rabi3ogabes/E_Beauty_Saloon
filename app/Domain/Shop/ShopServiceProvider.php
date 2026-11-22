<?php

namespace App\Domain\Shop;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\CRM\Models\Client;
use App\Domain\Shop\Delivery\DeliveryPartners;
use App\Domain\Shop\Events\OrderPlaced;
use App\Domain\Shop\Events\OrderStatusChanged;
use App\Domain\Shop\Listeners\SendOrderUpdate;
use App\Domain\Shop\Models\Address;
use App\Domain\Shop\Models\Order;
use App\Domain\Shop\Models\ProductReview;
use App\Domain\Shop\Support\SearchIndexer;
use App\Http\Controllers\Site\Shop\AccountOrdersController;
use App\Support\Account\AccountSections;
use App\Support\Tenancy\BranchScope;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ShopServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DeliveryPartners::class);
    }

    public function boot(): void
    {
        Client::resolveRelationUsing('orders', fn (Client $c) => $c->hasMany(Order::class)->withoutGlobalScope(BranchScope::class)->latest('id'));
        Client::resolveRelationUsing('addresses', fn (Client $c) => $c->hasMany(Address::class)->orderByDesc('is_default')->latest('id'));
        Product::resolveRelationUsing('reviews', fn (Product $p) => $p->hasMany(ProductReview::class)->where('is_published', true)->latest('id'));

        Event::listen(OrderPlaced::class, SendOrderUpdate::class);
        Event::listen(OrderStatusChanged::class, SendOrderUpdate::class);

        // Arabic-aware search index stays in sync with the catalog.
        $reindex = fn ($productId) => $productId && app(SearchIndexer::class)->index((int) $productId);
        Product::saved(fn (Product $p) => $reindex($p->id));
        Product::deleted(fn (Product $p) => $reindex($p->id));
        Product::restored(fn (Product $p) => $reindex($p->id));
        ProductVariant::saved(fn (ProductVariant $v) => $reindex($v->product_id));
        ProductVariant::deleted(fn (ProductVariant $v) => $reindex($v->product_id));
        Brand::saved(fn (Brand $b) => Product::withoutGlobalScopes()->where('brand_id', $b->id)->pluck('id')->each($reindex));

        app(AccountSections::class)->register(
            'orders',
            fn () => __('shop.account.title'),
            'shopping-bag',
            fn ($client, $request) => app(AccountOrdersController::class)->render($client, $request),
            order: 20,
            module: 'shop',
        );
    }
}
