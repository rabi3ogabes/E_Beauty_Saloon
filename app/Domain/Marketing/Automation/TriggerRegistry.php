<?php

namespace App\Domain\Marketing\Automation;

use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Models\Automation;
use Closure;

/**
 * Automation triggers by key. Event triggers are fired by code:
 *     app(AutomationEngine::class)->fire('abandoned_cart', $client, ['dedupe' => 'cart:'.$cart->id, 'cart_url' => $url, 'total' => 25000]);
 * Time-based triggers also register a daily scanner returning [client, context] pairs for the current tenant.
 * Any module can register its own trigger from its ServiceProvider: app(TriggerRegistry::class)->register('my_key', ...).
 */
class TriggerRegistry
{
    /** @var array<string, array{label: Closure, scanner: ?Closure, config: array}> */
    protected array $triggers = [];

    /**
     * @param  Closure(): string  $label
     * @param  null|Closure(Automation): iterable<array{0: Client, 1: array}>  $scanner
     * @param  array  $config  default trigger_config (e.g. ['days' => 60])
     */
    public function register(string $key, Closure $label, ?Closure $scanner = null, array $config = []): void
    {
        $this->triggers[$key] = ['label' => $label, 'scanner' => $scanner, 'config' => $config];
    }

    public function has(string $key): bool
    {
        return isset($this->triggers[$key]);
    }

    public function scanner(string $key): ?Closure
    {
        return $this->triggers[$key]['scanner'] ?? null;
    }

    public function defaults(string $key): array
    {
        return $this->triggers[$key]['config'] ?? [];
    }

    /** @return array<string, string> key => label */
    public function options(): array
    {
        return collect($this->triggers)->map(fn ($t) => ($t['label'])())->all();
    }

    public function keys(): array
    {
        return array_keys($this->triggers);
    }
}
