<?php

namespace App\Domain\Shop\Delivery;

use App\Domain\Shop\Contracts\DeliveryPartner;
use Closure;

/** Registry of delivery partner drivers (singleton). Unknown keys fall back to the manual partner. */
class DeliveryPartners
{
    /** @var array<string, Closure> */
    protected array $resolvers = [];

    public function extend(string $key, Closure $resolver): void
    {
        $this->resolvers[$key] = $resolver;
    }

    public function driver(string $key): DeliveryPartner
    {
        return isset($this->resolvers[$key]) ? ($this->resolvers[$key])(tenant()) : new ManualPartner;
    }

    /** @return array<string, string> key => label */
    public function options(): array
    {
        return ['internal' => __('shop.driver.internal'), 'manual' => __('shop.driver.manual')]
            + collect(array_keys($this->resolvers))->mapWithKeys(fn ($k) => [$k => ucfirst($k)])->all();
    }
}
