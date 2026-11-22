<?php

namespace App\Domain\Insights\Support;

use App\Domain\AIStudio\Actions\CreditLedger;
use App\Domain\AIStudio\Exceptions\NoProviderAvailable;
use App\Domain\AIStudio\Models\AITaskPrice;
use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\AIStudio\Providers\AbstractDriver;
use App\Domain\AIStudio\Support\AIRouter;
use Illuminate\Support\Str;

/**
 * Text AI for the smart layer (insight copy, ask-your-data planning, assistants) through the AI Studio router
 * (BYOK → preference → priority, automatic fallback). Credits are charged only after a successful completion,
 * with an idempotency key. Returns null whenever AI is unavailable (module off, empty wallet, no provider,
 * all providers failed) so every caller has a deterministic fallback.
 */
class SmartText
{
    /** Default credit cost and preferred drivers per smart-layer task (overridable via ai_task_prices rows). */
    public const TASKS = [
        'insights_copy' => 1,
        'insights_ask' => 1,
        'assistant_reply' => 1,
        'content_assistant' => 1,
    ];

    public function __construct(protected AIRouter $router, protected CreditLedger $ledger) {}

    public function credits(string $task): int
    {
        $row = AITaskPrice::query()->where('task', $task)->first();

        return (int) ($row?->credits ?? self::TASKS[$task] ?? 1);
    }

    public function available(string $task): bool
    {
        $tenant = tenant();

        return (bool) $tenant?->hasModule('ai_studio') && AIWallet::for($tenant)->balance >= $this->credits($task);
    }

    /** @return array{text: string, provider: string, credits: int}|null */
    public function complete(string $task, string $prompt, ?string $idempotencyKey = null): ?array
    {
        if (! $this->available($task)) {
            return null;
        }
        $tenant = tenant();
        try {
            // Provider preference: the task's own ai_task_prices row if the platform added one, else the text-copy order.
            $routeAs = AITaskPrice::query()->where('task', $task)->exists() ? $task : 'marketing_copy';
            $result = $this->router->run($routeAs, 'text', fn (AbstractDriver $driver) => $driver->completeText($prompt), $tenant->id);
        } catch (NoProviderAvailable) {
            return null;
        }
        $credits = $this->credits($task);
        $this->ledger->debit($tenant, $credits, 'charge', $idempotencyKey ?? $task.':'.Str::uuid(),
            __('ai_studio.wallet.charge', ['task' => $task]), 'smart_text', null, allowNegative: true);

        return ['text' => (string) $result->result, 'provider' => $result->provider->driver, 'credits' => $credits];
    }
}
