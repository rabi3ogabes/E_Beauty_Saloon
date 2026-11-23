<?php

namespace App\Domain\Insights\Ask;

use App\Domain\AIStudio\Providers\AbstractDriver;
use App\Domain\Insights\Support\SmartText;
use Carbon\CarbonImmutable;

/**
 * "Ask your data": the AI (or the keyword fallback) only turns the question into a JSON plan
 * {metric, filters, period}; QueryCatalog validates it against the whitelist and runs a parameterised, read-only,
 * tenant-scoped ReportService query. The answer is composed from the numbers by a template and returned together
 * with the plan and the numbers used. The AI never sees client data or query results, and never writes SQL.
 */
class AskYourData
{
    public function __construct(protected QueryCatalog $catalog, protected RulePlanner $rules, protected SmartText $ai) {}

    /** @return array{ok:bool, answer:string, plan:?array, rows:list<array{0:string,1:string}>, source:string, period:?string} */
    public function ask(string $question): array
    {
        $question = trim(mb_substr($question, 0, 300));
        if ($question === '') {
            return $this->fail(__('insights.ask.empty'), null, 'none');
        }

        [$plan, $source] = $this->plan($question);
        if (! $plan) {
            return $this->fail(__('insights.ask.not_understood'), null, $source);
        }

        return $this->answerPlan($plan, $source);
    }

    public function answerPlan(mixed $plan, string $source = 'manual'): array
    {
        try {
            [$metric, $filters, $period, $labels] = $this->catalog->validate($plan);
        } catch (RejectedPlan $e) {
            return $this->fail($e->getMessage(), is_array($plan) ? $plan : null, $source);
        }
        $result = $this->catalog->run($metric, $filters, $period);
        $scope = collect($labels)->map(fn ($label, $key) => __('insights.ask.scope.'.$key, ['name' => $label]))->implode(' ');

        return [
            'ok' => true,
            'answer' => __('insights.ask.answers.'.$metric, [
                'value' => $result['rows'][0][1] ?? '—',
                'top' => $result['rows'][0][0] ?? '—',
                'scope' => $scope !== '' ? ' '.$scope : '',
                'period' => $period->label(),
            ]),
            'plan' => $plan,
            'rows' => $result['rows'],
            'source' => $source,
            'period' => $period->label(),
        ];
    }

    /** @return array{0: ?array, 1: string} */
    protected function plan(string $question): array
    {
        $catalog = $this->catalog->describe();
        $today = CarbonImmutable::now(tenant()?->timezone ?? 'UTC')->toDateString();
        $prompt = 'Translate the salon owner\'s question into ONE query plan from this whitelist. Never write SQL. '
            .'Reply ONLY with JSON {"metric": string, "filters": object, "period": string|object}. '
            .'Allowed metrics and their filters: '.json_encode($catalog['metrics'])
            .'. Periods: '.json_encode($catalog['periods']).". Today is {$today}. "
            .'Known branches: '.json_encode($catalog['branches'], JSON_UNESCAPED_UNICODE)
            .'. Known service categories: '.json_encode($catalog['service_categories'], JSON_UNESCAPED_UNICODE)
            .'. If the question cannot be answered with the whitelist reply {"metric": "unsupported"}. Question: '.json_encode($question, JSON_UNESCAPED_UNICODE);

        if ($reply = $this->ai->complete('insights_ask', $prompt)) {
            try {
                return [AbstractDriver::extractJson($reply['text']), 'ai'];
            } catch (\Throwable) {
                // fall through to the keyword planner
            }
        }

        return [$this->rules->plan($question), 'rules'];
    }

    protected function fail(string $message, ?array $plan, string $source): array
    {
        return ['ok' => false, 'answer' => $message, 'plan' => $plan, 'rows' => [], 'source' => $source, 'period' => null];
    }
}
