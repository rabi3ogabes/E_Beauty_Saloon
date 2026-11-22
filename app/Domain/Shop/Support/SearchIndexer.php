<?php

namespace App\Domain\Shop\Support;

use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Maintains shop_search_index: one normalized text blob per product with names (all locales),
 * brand, category names, SKUs and barcodes. Kept in sync by model events (ShopServiceProvider).
 */
class SearchIndexer
{
    public function index(Product|int $product): void
    {
        $product = $product instanceof Product
            ? $product
            : Product::withoutGlobalScopes()->withTrashed()->find($product);
        if (! $product) {
            return;
        }
        if ($product->trashed()) {
            DB::table('shop_search_index')->where('product_id', $product->id)->delete();

            return;
        }

        $product->loadMissing(['brand' => fn ($q) => $q->withoutGlobalScopes(), 'category' => fn ($q) => $q->withoutGlobalScopes(), 'variants' => fn ($q) => $q->withoutGlobalScopes()]);
        $parts = array_merge(
            array_values($product->getTranslations('name')),
            [$product->brand?->name],
            array_values($product->category?->getTranslations('name') ?? []),
            $product->variants->flatMap(fn ($v) => array_merge([$v->sku, $v->barcode], array_values($v->getTranslations('name'))))->all(),
        );
        $content = ArabicNormalizer::normalize(implode(' ', array_filter($parts)));

        DB::table('shop_search_index')->updateOrInsert(
            ['product_id' => $product->id],
            ['tenant_id' => $product->tenant_id, 'content' => ' '.$content.' ', 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function reindexTenant(int $tenantId): int
    {
        $count = 0;
        Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->chunkById(200, function ($products) use (&$count) {
            foreach ($products as $p) {
                $this->index($p);
                $count++;
            }
        });

        return $count;
    }
}
