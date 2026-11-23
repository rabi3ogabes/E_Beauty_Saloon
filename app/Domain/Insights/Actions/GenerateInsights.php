<?php

namespace App\Domain\Insights\Actions;

use App\Domain\Insights\Models\InsightCard;
use App\Domain\Insights\Models\InsightRun;
use App\Domain\Insights\Rules\InsightCopywriter;
use App\Domain\Insights\Rules\InsightRules;
use App\Domain\Insights\Rules\MetricsSnapshot;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Nightly insight generation for ONE tenant, always inside TenantContext::run so every query is tenant-scoped.
 * Idempotent: one run per tenant per local day (insight_runs unique), and cards are upserted by
 * (tenant, fingerprint = type:scope:ISO-week), so re-runs refresh this week's open cards instead of duplicating;
 * cards the owner already acted on or dismissed keep their status.
 */
class GenerateInsights
{
    public function __construct(
        protected TenantContext $context,
        protected MetricsSnapshot $snapshot,
        protected InsightRules $rules,
        protected InsightCopywriter $copy,
    ) {}

    public function handle(Tenant $tenant, ?CarbonImmutable $now = null, bool $force = false): InsightRun
    {
        $now ??= CarbonImmutable::now();

        return $this->context->run($tenant, function () use ($tenant, $now, $force) {
            $local = $now->setTimezone($tenant->timezone ?: config('app.timezone'));
            $run = InsightRun::query()->whereDate('run_date', $local->toDateString())->first()
                ?? InsightRun::create(['run_date' => $local->toDateString(), 'status' => 'running']);
            if ($run->status === 'done' && ! $force) {
                return $run;
            }

            try {
                $metrics = $this->snapshot->build($now);
                $candidates = $this->rules->evaluate($metrics);
                $week = $local->format('o-\WW');
                $copy = $this->copy->write($candidates, $local->toDateString());

                foreach ($candidates as $c) {
                    $key = $this->copy->key($c);
                    $card = InsightCard::firstOrNew(['fingerprint' => $key.':'.$week]);
                    if ($card->exists && $card->status !== 'new') {
                        continue;
                    }
                    $card->fill([
                        'branch_id' => $c['branch_id'],
                        'type' => $c['type'],
                        'period_key' => $week,
                        'title' => $copy[$key]['title'],
                        'body' => $copy[$key]['body'],
                        'metrics' => $c['metrics'],
                        'action' => $c['action'],
                        'priority' => $c['priority'],
                        'source' => $copy[$key]['source'],
                    ])->save();
                }

                $run->update(['status' => 'done', 'cards' => count($candidates), 'used_ai' => $this->copy->usedAi, 'error' => null]);
            } catch (\Throwable $e) {
                $run->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)]);
                report($e);
            }

            return $run->fresh();
        });
    }
}
