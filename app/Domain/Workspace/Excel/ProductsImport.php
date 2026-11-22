<?php

namespace App\Domain\Workspace\Excel;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Support\Str;

/** One row = one variant (SKU). Rows sharing name_en belong to the same product. */
class ProductsImport extends WorkspaceImport
{
    public static function columns(): array
    {
        return ['name_en', 'name_ar', 'variant_en', 'variant_ar', 'brand', 'category', 'sku', 'barcode', 'cost', 'price', 'sale_price', 'usage'];
    }

    public static function example(): array
    {
        return ['name_en' => 'Repair Shampoo', 'name_ar' => 'شامبو مرمم', 'variant_en' => '250ml', 'variant_ar' => '٢٥٠ مل', 'brand' => 'Kérastase',
            'category' => 'Hair care', 'sku' => 'KER-SH-250', 'barcode' => '3474636397396', 'cost' => '75.00', 'price' => '145.00', 'sale_price' => '', 'usage' => 'retail'];
    }

    public static function exportRows(): array
    {
        return ProductVariant::query()->with(['product.brand', 'product.category'])->orderBy('product_id')->get()
            ->filter(fn ($v) => $v->product)->map(fn (ProductVariant $v) => [
                'name_en' => $v->product->getTranslation('name', 'en', false), 'name_ar' => $v->product->getTranslation('name', 'ar', false),
                'variant_en' => $v->getTranslation('name', 'en', false), 'variant_ar' => $v->getTranslation('name', 'ar', false),
                'brand' => $v->product->brand?->name, 'category' => $v->product->category?->getTranslation('name', 'en', false),
                'sku' => $v->sku, 'barcode' => $v->barcode, 'cost' => static::decimal((int) $v->cost), 'price' => static::decimal((int) $v->price),
                'sale_price' => static::decimal($v->sale_price), 'usage' => $v->product->usage,
            ])->values()->all();
    }

    protected function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:60'],
            'barcode' => ['nullable', 'max:60'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'lte:price'],
            'usage' => ['nullable', 'in:retail,professional,both'],
        ];
    }

    protected function importRow(array $row): string
    {
        $brandId = filled($row['brand'] ?? null)
            ? Brand::firstOrCreate(['slug' => Str::slug($row['brand']) ?: Str::lower(Str::random(6))], ['name' => $row['brand']])->id : null;
        $categoryId = filled($row['category'] ?? null)
            ? ProductCategory::firstOrCreate(['slug' => Str::slug($row['category']) ?: Str::lower(Str::random(6))], ['name' => ['en' => $row['category']]])->id : null;

        $product = Product::withTrashed()->firstOrNew(['slug' => Str::slug($row['name_en']) ?: Str::lower(Str::random(8))]);
        $product->fill([
            'name' => array_filter(['en' => $row['name_en'], 'ar' => $row['name_ar'] ?? null]),
            'brand_id' => $brandId ?? $product->brand_id,
            'product_category_id' => $categoryId ?? $product->product_category_id,
            'usage' => $row['usage'] ?: ($product->usage ?? 'retail'),
            'is_active' => true,
        ]);
        $product->deleted_at = null;
        $product->save();

        $variant = ProductVariant::firstOrNew(['sku' => (string) $row['sku']]);
        $exists = $variant->exists;
        $variant->fill([
            'product_id' => $product->id,
            'name' => array_filter(['en' => $row['variant_en'] ?? null, 'ar' => $row['variant_ar'] ?? null]) ?: null,
            'barcode' => filled($row['barcode'] ?? null) ? (string) $row['barcode'] : null,
            'cost' => $this->money($row['cost'] ?? null) ?? 0,
            'price' => $this->money($row['price']),
            'sale_price' => $this->money($row['sale_price'] ?? null),
            'is_default' => ! $product->variants()->exists() || $variant->is_default,
        ]);
        $variant->save();

        return $exists ? 'updated' : 'created';
    }
}
