<?php

namespace App\Domain\POS\Actions;

use App\Domain\POS\Enums\SaleStatus;
use App\Domain\POS\Models\Sale;
use App\Models\User;
use App\Support\Exceptions\DomainException;

/**
 * Replays sales queued in the terminal's IndexedDB while offline. Each sale carries the ULID the
 * tablet generated, so a replay (flaky network, double tap, second tab) is recognised and never booked twice.
 * Offline sales may push stock below zero — they are accepted and reported as conflicts.
 */
class SyncOfflineSales
{
    public function __construct(protected CompleteSale $complete) {}

    /**
     * @param  array<int, array<string, mixed>>  $sales
     * @return array{results: array<int, array<string, mixed>>, conflicts: array<int, array<string, mixed>>}
     */
    public function handle(array $sales, User $cashier): array
    {
        $results = [];
        $conflicts = [];

        foreach (array_slice(array_values($sales), 0, 200) as $payload) {
            $key = (string) ($payload['idempotency_key'] ?? '');
            if ($key === '' || ! preg_match('/^[0-9A-Za-z_-]{10,64}$/', $key)) {
                $results[] = ['idempotency_key' => $key, 'status' => 'failed', 'error' => __('pos.errors.missing_key')];

                continue;
            }

            $existing = Sale::withoutGlobalScopes()->where('tenant_id', tenant()->id)->where('idempotency_key', $key)->first();
            if ($existing && $existing->status !== SaleStatus::Draft) {
                $results[] = ['idempotency_key' => $key, 'status' => 'duplicate', 'sale_id' => $existing->id, 'number' => $existing->number];

                continue;
            }

            try {
                $sale = $this->complete->handle($payload, $cashier, offline: true);
                $results[] = ['idempotency_key' => $key, 'status' => 'created', 'sale_id' => $sale->id, 'number' => $sale->number];
                foreach ($sale->sync_conflicts ?? [] as $conflict) {
                    $conflicts[] = $conflict + ['sale_id' => $sale->id, 'number' => $sale->number];
                }
            } catch (DomainException $e) {
                $results[] = ['idempotency_key' => $key, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        if ($conflicts) {
            activity()->causedBy($cashier)->withProperties(['conflicts' => $conflicts])->log('pos.sync.stock_conflicts');
        }

        return ['results' => $results, 'conflicts' => $conflicts];
    }
}
