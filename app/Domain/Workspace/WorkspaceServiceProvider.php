<?php

namespace App\Domain\Workspace;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\BranchStock;
use Illuminate\Support\ServiceProvider;

/**
 * Owner workspace (/dashboard): relations onto core models used by the workspace screens.
 */
class WorkspaceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Stock rows of every variant of a product (read-only "stock per branch" view).
        Product::resolveRelationUsing('stockRows', fn (Product $product) => $product->hasManyThrough(
            BranchStock::class, ProductVariant::class, 'product_id', 'product_variant_id'
        ));
    }
}
