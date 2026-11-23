<?php

namespace App\Domain\Insights\Rules;

use App\Domain\AIStudio\Providers\AbstractDriver;
use App\Domain\Insights\Support\InsightSettings;
use App\Domain\Insights\Support\SmartText;
use Carbon\CarbonImmutable;

/**
 * Bilingual copy for insight candidates: one AI call per run (aggregated metrics only, never client data) asking
 * for en + ar titles/bodies; any card missing from the AI answer — or AI being off/unavailable — falls back to the
 * translated templates insights.cards.{type}.{title,body}.
 */
class InsightCopywriter
{
    public bool $usedAi = false;

    /** The exact prompt last sent to AI (kept for auditing / tests). */
    public ?string $lastPrompt = null;

    public function __construct(protected SmartText $ai) {}

    /** @return array<string, array{title: array{en:string, ar:string}, body: array{en:string, ar:string}, source: string}> keyed by candidate key */
    public function write(array $candidates, string $runKey): array
    {
        $out = [];
        foreach ($candidates as $c) {
            $out[$this->key($c)] = ['title' => [], 'body' => [], 'source' => 'template'];
            foreach (['en', 'ar'] as $locale) {
                $params = $this->localParams($c['params'], $locale);
                $out[$this->key($c)]['title'][$locale] = __('insights.cards.'.$c['type'].'.title', $params, $locale);
                $out[$this->key($c)]['body'][$locale] = __('insights.cards.'.$c['type'].'.body', $params, $locale);
            }
        }
        $this->usedAi = false;
        if (! $candidates || ! InsightSettings::get('ai_copy', true)) {
            return $out;
        }

        $payload = array_map(fn ($c) => [
            'key' => $this->key($c), 'type' => $c['type'], 'metrics' => $c['metrics'],
            'draft_en' => $out[$this->key($c)]['title']['en'].' — '.$out[$this->key($c)]['body']['en'],
            'action' => $c['action']['type'] ?? null,
        ], $candidates);
        $this->lastPrompt = 'You are the business analyst of a luxury beauty salon. Rewrite these weekly insight cards for the owner in a warm, confident, concise tone. '
            .'Use ONLY the aggregated metrics given (money is in minor units of '.(tenant()?->currency ?? 'QAR').'; keep every number exactly as in the draft). '
            .'For each card return an English title (max 80 chars) and body (max 220 chars) and the same in elegant Gulf-friendly Arabic. '
            .'Reply ONLY with JSON: {"cards":[{"key":"...","en":{"title":"...","body":"..."},"ar":{"title":"...","body":"..."}}]}'."\nCards: "
            .json_encode($payload, JSON_UNESCAPED_UNICODE);

        $reply = $this->ai->complete('insights_copy', $this->lastPrompt, 'insights:'.tenant()->id.':'.$runKey);
        if (! $reply) {
            return $out;
        }
        try {
            $json = AbstractDriver::extractJson($reply['text']);
        } catch (\Throwable) {
            return $out;
        }
        foreach ((array) ($json['cards'] ?? []) as $card) {
            $key = (string) ($card['key'] ?? '');
            if (! isset($out[$key])) {
                continue;
            }
            $ok = true;
            foreach (['en', 'ar'] as $l) {
                $ok = $ok && is_string($card[$l]['title'] ?? null) && trim($card[$l]['title']) !== '' && is_string($card[$l]['body'] ?? null);
            }
            if (! $ok) {
                continue;
            }
            foreach (['en', 'ar'] as $l) {
                $out[$key]['title'][$l] = mb_substr(trim($card[$l]['title']), 0, 120);
                $out[$key]['body'][$l] = mb_substr(trim($card[$l]['body']), 0, 400);
            }
            $out[$key]['source'] = 'ai';
            $this->usedAi = true;
        }

        return $out;
    }

    public function key(array $candidate): string
    {
        return $candidate['type'].':'.$candidate['scope'];
    }

    /** Weekday numbers and ISO dates become localized names. */
    protected function localParams(array $params, string $locale): array
    {
        if (isset($params['weekday'])) {
            $params['day'] = CarbonImmutable::now()->startOfWeek(CarbonImmutable::SUNDAY)->addDays((int) $params['weekday'])->locale($locale)->dayName;
            unset($params['weekday']);
        }
        if (isset($params['date'])) {
            $params['day'] = CarbonImmutable::parse($params['date'])->locale($locale)->isoFormat('dddd D MMM');
            unset($params['date']);
        }

        return $params;
    }
}
