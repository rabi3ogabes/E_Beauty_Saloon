<?php

namespace App\Domain\Marketing\Automation;

use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Models\Tag;
use App\Domain\Loyalty\Actions\LoyaltyLedger;
use App\Domain\Marketing\Models\Automation;
use App\Domain\Marketing\Models\AutomationRun;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Models\WhatsappTemplate;
use App\Domain\Marketing\Services\MarketingMessenger;
use App\Domain\Marketing\Services\MessageRenderer;
use App\Domain\Marketing\Services\QuietHours;
use App\Domain\Marketing\Support\MarketingUrl;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Support\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Runs automation flows. PUBLIC CONTRACT:
 *
 *     app(AutomationEngine::class)->fire('abandoned_cart', $client, ['dedupe' => 'cart:42', 'cart_url' => url('/cart'), 'total' => 25000]);
 *
 * fire() starts one run per active automation listening to the trigger (must be called inside the tenant context).
 * Runs are idempotent per (automation, client, dedupe key — default: today's date). Steps run top to bottom;
 * "wait" parks the run (resume_at) and the scheduler resumes it; a false "condition" stops it. Marketing sends respect
 * consent (fallback to consented channels) and quiet hours (the run waits until the window ends).
 * Context values are available in message bodies as {key} placeholders (e.g. {cart_url}).
 */
class AutomationEngine
{
    public function __construct(
        protected MarketingMessenger $messenger,
        protected MessageRenderer $renderer,
        protected QuietHours $quietHours,
        protected LoyaltyLedger $ledger,
    ) {}

    /** @return list<AutomationRun> */
    public function fire(string $trigger, Client $client, array $context = []): array
    {
        if (! tenant()?->hasModule('marketing')) {
            return [];
        }
        $runs = [];
        foreach (Automation::where('trigger', $trigger)->where('is_active', true)->get() as $automation) {
            if ($run = $this->start($automation, $client, $context)) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    public function start(Automation $automation, Client $client, array $context = []): ?AutomationRun
    {
        $dedupe = (string) ($context['dedupe'] ?? now(tenant()?->timezone ?? 'UTC')->toDateString());
        $key = 'automation:'.$automation->id.':'.$client->id.':'.$dedupe;
        if (AutomationRun::where('idempotency_key', $key)->exists()) {
            return null;
        }
        try {
            $run = AutomationRun::create([
                'automation_id' => $automation->id, 'client_id' => $client->id, 'trigger' => $automation->trigger,
                'context' => $context, 'status' => 'running', 'idempotency_key' => $key,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        $automation->increment('runs_count');
        $automation->forceFill(['last_run_at' => now()])->saveQuietly();

        return $this->process($run);
    }

    public function process(AutomationRun $run): AutomationRun
    {
        $automation = $run->automation;
        $client = $run->client;
        if (! $automation || ! $client || ! in_array($run->status, ['running', 'waiting'], true)) {
            return $run;
        }
        $steps = array_values($automation->steps ?? []);
        $run->status = 'running';
        $run->resume_at = null;

        while ($run->current_step < count($steps)) {
            $step = $steps[$run->current_step];
            $type = $step['type'] ?? null;

            if ($type === 'wait') {
                $minutes = (int) ($step['minutes'] ?? 0) + (int) ($step['hours'] ?? 0) * 60 + (int) ($step['days'] ?? 0) * 1440;
                $run->current_step++;
                if ($minutes > 0) {
                    $run->status = 'waiting';
                    $run->resume_at = now()->addMinutes($minutes);
                    $run->addLog("wait {$minutes}m");
                    $run->save();

                    return $run;
                }

                continue;
            }

            if ($type === 'condition' && ! $this->check($step, $client, $run)) {
                $run->addLog('condition false: '.($step['check'] ?? '?'));

                return $this->finish($run, 'stopped');
            }

            if ($type === 'send_message') {
                if ($resume = $this->quietHours->nextAllowed($client)) {
                    $run->status = 'waiting';
                    $run->resume_at = $resume;
                    $run->addLog('quiet hours');
                    $run->save();

                    return $run;
                }
                $this->send($step, $client, $run);
            }

            if ($type === 'award_points' && tenant()?->hasModule('loyalty') && ($points = (int) ($step['points'] ?? 0)) > 0) {
                $this->ledger->award($client, $points, 'bonus', $run, 'automation-run:'.$run->id.':'.$run->current_step, $automation->name);
                $run->addLog("awarded {$points} points");
            }

            if ($type === 'add_tag' && ($name = trim((string) ($step['tag'] ?? ''))) !== '') {
                $client->tags()->syncWithoutDetaching([Tag::firstOrCreate(['name' => $name])->id]);
                $run->addLog("tag {$name}");
            }

            if ($type === 'coupon') {
                $context = $run->context ?? [];
                $context['code'] = $this->personalCoupon($step, $client, $automation)->code;
                $run->context = $context;
                $run->addLog('coupon '.$context['code']);
            }

            $run->current_step++;
        }

        return $this->finish($run, 'completed');
    }

    protected function finish(AutomationRun $run, string $status): AutomationRun
    {
        $run->status = $status;
        $run->completed_at = now();
        $run->resume_at = null;
        $run->save();

        return $run;
    }

    protected function check(array $step, Client $client, AutomationRun $run): bool
    {
        $value = $step['value'] ?? null;

        return match ($step['check'] ?? null) {
            'has_consent' => $client->hasConsent((string) ($value ?: 'whatsapp')),
            'not_visited_since_start' => $client->fresh()->last_visit_at === null || $client->fresh()->last_visit_at->lt($run->created_at),
            'no_booking_since_start' => ! Booking::withoutGlobalScopes()->where('client_id', $client->id)->where('created_at', '>=', $run->created_at)->exists(),
            'tier_is' => ($client->loyalty_tier ?: 'silver') === $value,
            'has_tag' => $client->tags()->where('name', $value)->exists(),
            'wallet_below' => (int) $client->wallet_balance < Money::fromDecimal((string) ($value ?: 0), tenant()?->currency ?? 'QAR')->amount,
            default => true,
        };
    }

    protected function send(array $step, Client $client, AutomationRun $run): void
    {
        $locale = $client->locale ?: 'en';
        $context = collect($run->context ?? [])->filter(fn ($v) => is_scalar($v))->all();
        $path = strtr((string) ($step['link'] ?? '/book'), collect($context)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => (string) $v])->all());
        $extra = array_merge(['link' => MarketingUrl::to($path), 'unsubscribe' => MarketingUrl::to('/account')], $context);
        $body = $this->renderer->render($this->renderer->localized($step['body'] ?? '', $locale), $client, $extra);
        $subject = $this->renderer->render($this->renderer->localized($step['subject'] ?? '', $locale), $client, $extra) ?: tenant()?->name;
        $template = ! empty($step['template']) ? WhatsappTemplate::where('name', $step['template'])->where('status', 'approved')
            ->orderByRaw('language = ? desc', [$locale])->first() : null;
        $channels = $step['channels'] ?? ['whatsapp', 'sms'];
        $transactional = (bool) ($step['transactional'] ?? false);

        [$channel, $result] = $this->messenger->send($client, $channels, function (string $channel, string $to) use ($body, $subject, $template, $client, $extra, $locale, $run) {
            $meta = ['automation_id' => $run->automation_id, 'automation_run_id' => $run->id, 'reference' => 'automation:'.$run->automation_id];
            if ($channel === 'whatsapp' && $template) {
                return new OutboundMessage($to, $body, template: $template->name, variables: array_slice([$client->firstName(), $extra['link'], tenant()?->name], 0, $template->placeholderCount()),
                    mediaUrl: $template->header_media_url, locale: $template->language, meta: $meta);
            }
            $buttons = [['type' => 'url', 'text' => __('marketing.campaigns.default_cta', [], $locale), 'url' => $extra['link']]];

            return new OutboundMessage($to, $channel === 'email' ? nl2br(e($body)) : $body, subject: $subject, buttons: $channel === 'sms' ? [] : $buttons, locale: $locale, meta: $meta);
        }, requireConsent: ! $transactional);

        $run->addLog($result->ok ? "sent via {$channel}" : 'not sent: '.$result->error);
    }

    protected function personalCoupon(array $step, Client $client, Automation $automation): Promotion
    {
        return Promotion::create([
            'name' => ['en' => $automation->name, 'ar' => $automation->name],
            'type' => 'coupon',
            'code' => strtoupper(Str::limit(Str::slug($automation->recipe ?? 'gift', ''), 6, '')).'-'.strtoupper(Str::random(5)),
            'discount_type' => 'percent',
            'discount_value' => (int) ($step['discount_bp'] ?? 1000),
            'rules' => ['client_id' => $client->id],
            'usage_limit' => 1,
            'ends_at' => now()->addDays((int) ($step['valid_days'] ?? 14)),
        ]);
    }

    /** Resume parked runs whose wait is over (scheduler, every 5 minutes). */
    public function resumeDue(): int
    {
        $count = 0;
        AutomationRun::where('status', 'waiting')->where('resume_at', '<=', now())->with(['automation', 'client.consents'])
            ->limit(500)->get()->each(function (AutomationRun $run) use (&$count) {
                if (! $run->automation?->is_active) {
                    $this->finish($run, 'stopped');

                    return;
                }
                $this->process($run);
                $count++;
            });

        return $count;
    }

    /** Daily scan of time-based triggers (birthday, win-back, rebook, ...) for the current tenant. */
    public function scan(TriggerRegistry $registry): int
    {
        $started = 0;
        foreach (Automation::where('is_active', true)->get() as $automation) {
            $scanner = $registry->scanner($automation->trigger);
            if (! $scanner) {
                continue;
            }
            foreach ($scanner($automation) as [$client, $context]) {
                $started += $this->start($automation, $client, $context) ? 1 : 0;
            }
        }

        return $started;
    }
}
