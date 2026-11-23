<?php

namespace App\Domain\POS\Support;

use App\Domain\Branches\Models\Branch;
use App\Support\Exceptions\DomainException;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * What the POS can sell. Services and products are registered by the POS module; other modules add
 * their own types from their ServiceProvider, e.g. the marketing module's packages:
 *
 *   app(SellableRegistry::class)->register('package',
 *       resolve: fn (int $id, Branch $branch) => ['model' => $package, 'name' => ..., 'unit_price' => ..., 'tax_percent' => null],
 *       catalog: fn (Branch $branch) => [['id' => 1, 'name' => 'Bridal glow x5', 'price' => 450000, 'category' => null], ...]);
 *
 * The terminal shows a tab for every type that has a catalog (the Packages tab appears automatically).
 */
class SellableRegistry
{
    /** @var array<string, array{resolve: Closure, catalog: ?Closure, onSold: ?Closure}> */
    protected array $types = [];

    /**
     * @param  Closure|null  $onSold  fn (SaleItem $item, Sale $sale, ?Client $client) — runs inside the sale transaction
     *                                (e.g. create the client's package). Must be idempotent per sale item.
     */
    public function register(string $type, Closure $resolve, ?Closure $catalog = null, ?Closure $onSold = null): void
    {
        $this->types[$type] = ['resolve' => $resolve, 'catalog' => $catalog, 'onSold' => $onSold];
    }

    public function sold(string $type, Model $item, Model $sale, ?Model $client): void
    {
        if ($callback = $this->types[$type]['onSold'] ?? null) {
            $callback($item, $sale, $client);
        }
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function hasCatalog(string $type): bool
    {
        return isset($this->types[$type]['catalog']);
    }

    /** @return array{model: Model, name: string, unit_price: int, tax_percent: ?float, track_stock?: bool} */
    public function resolve(string $type, int $id, Branch $branch, ?int $staffId = null): array
    {
        if (! $this->has($type)) {
            throw new DomainException(__('pos.errors.unknown_item'));
        }

        return ($this->types[$type]['resolve'])($id, $branch, $staffId);
    }

    public function catalog(string $type, Branch $branch): array
    {
        return $this->hasCatalog($type) ? ($this->types[$type]['catalog'])($branch) : [];
    }
}
