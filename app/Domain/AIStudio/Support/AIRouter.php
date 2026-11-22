<?php

namespace App\Domain\AIStudio\Support;

use App\Domain\AIStudio\Exceptions\NoProviderAvailable;
use App\Domain\AIStudio\Exceptions\ProviderException;
use App\Domain\AIStudio\Exceptions\ProviderTimeoutException;
use App\Domain\AIStudio\Models\AIProvider;
use App\Domain\AIStudio\Models\AITaskPrice;
use App\Domain\AIStudio\Providers\AbstractDriver;
use App\Domain\AIStudio\Providers\ProviderFactory;
use Closure;
use Illuminate\Support\Collection;

/**
 * Picks the provider for a task by (BYOK first) → task preference order → priority, unit cost,
 * latency EMA and health; falls back to the next candidate on any failure or timeout.
 */
class AIRouter
{
    public function __construct(protected ProviderFactory $factory) {}

    /** @return Collection<int, AIProvider> ordered best-first */
    public function candidates(string $task, string $capability, ?int $tenantId = null): Collection
    {
        $preferred = array_values((array) AITaskPrice::for($task)->preferred_providers);

        return AIProvider::query()->availableFor($tenantId)->get()
            ->filter(fn (AIProvider $p) => $p->supports($capability) && $p->isRoutable())
            ->sortBy(function (AIProvider $p) use ($preferred, $capability) {
                $byok = $p->tenant_id ? 0 : 1;
                $pref = array_search($p->driver, $preferred, true);
                $pref = $pref === false ? 99 : $pref;
                $health = match ($p->health_status) {
                    'healthy' => 0,
                    'degraded' => 1,
                    default => 2,
                };
                // Lower is better. Priority dominates; cost and latency break ties.
                $score = $p->priority * 1000 + $p->unitCost($capability) * 10 + intdiv((int) ($p->latency_ms_ema ?? 0), 100);

                return sprintf('%d-%d-%02d-%012d', $byok, $health, $pref, $score);
            })
            ->values();
    }

    /**
     * Run $call against candidates until one succeeds.
     *
     * @param  Closure(AbstractDriver, AIProvider): mixed  $call
     */
    public function run(string $task, string $capability, Closure $call, ?int $tenantId = null): RouteResult
    {
        $attempts = [];
        foreach ($this->candidates($task, $capability, $tenantId) as $provider) {
            $started = hrtime(true);
            try {
                $result = $call($this->factory->make($provider), $provider);
                $ms = (int) ((hrtime(true) - $started) / 1_000_000);
                $provider->recordSuccess($ms);
                $attempts[] = ['provider' => $provider->name, 'driver' => $provider->driver, 'ok' => true, 'ms' => $ms];

                return new RouteResult($result, $provider, $ms, $attempts);
            } catch (ProviderException $e) {
                $ms = (int) ((hrtime(true) - $started) / 1_000_000);
                $provider->recordFailure($e->getMessage(), $ms);
                $attempts[] = [
                    'provider' => $provider->name, 'driver' => $provider->driver, 'ok' => false, 'ms' => $ms,
                    'error' => $e instanceof ProviderTimeoutException ? 'timeout' : mb_substr($e->getMessage(), 0, 160),
                ];
            }
        }

        throw new NoProviderAvailable($attempts ? __('ai_studio.errors.all_providers_failed') : __('ai_studio.errors.no_provider'), $attempts);
    }
}
