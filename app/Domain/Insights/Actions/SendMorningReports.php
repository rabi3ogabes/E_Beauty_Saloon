<?php

namespace App\Domain\Insights\Actions;

use App\Domain\Branches\Models\Branch;
use App\Domain\Insights\Models\ReportDelivery;
use App\Domain\Insights\Support\InsightSettings;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Opt-in morning report (settings.insights.morning_report): runs every 15 minutes and sends each branch's report
 * once per local business day, in the hour starting at the configured branch-local time (default 08:00), by
 * email and/or WhatsApp to the owner. Idempotent via report_deliveries (tenant, branch, business date).
 */
class SendMorningReports
{
    public function __construct(protected TenantContext $context, protected BuildMorningReport $builder, protected MessagingManager $messaging) {}

    /** @return int reports sent */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $sent = 0;
        Tenant::query()->whereIn('status', ['trial', 'active', 'grace'])->each(function (Tenant $tenant) use ($now, &$sent) {
            if (! InsightSettings::get('morning_report.enabled', false, $tenant)) {
                return;
            }
            $sent += $this->context->run($tenant, fn () => $this->forTenant($tenant, $now));
        });

        return $sent;
    }

    public function forTenant(Tenant $tenant, CarbonImmutable $now): int
    {
        $settings = InsightSettings::get('morning_report', [], $tenant);
        [$h, $m] = array_map('intval', explode(':', ($settings['time'] ?? '08:00').':00'));
        $owner = $tenant->owner;
        $count = 0;

        foreach (Branch::query()->active()->orderBy('sort')->get() as $branch) {
            $local = $now->setTimezone($branch->timezone ?: $tenant->timezone);
            $start = $local->setTime($h, $m);
            if ($local->lt($start) || $local->gte($start->addHour())) {
                continue;
            }
            if (ReportDelivery::where('branch_id', $branch->id)->whereDate('business_date', $local->toDateString())->exists()) {
                continue;
            }
            try {
                $delivery = ReportDelivery::create(['branch_id' => $branch->id, 'business_date' => $local->toDateString()]);
            } catch (UniqueConstraintViolationException) {
                continue; // another worker got there first
            }

            $report = $this->builder->handle($branch, $now);
            $locale = $owner?->locale ?: ($tenant->default_locale ?: 'en');
            $text = $this->builder->text($report, $locale);
            $channels = [];
            if (($settings['email'] ?? true) && ($email = $owner?->email ?: $tenant->email)) {
                $channels['email'] = $this->messaging->send('email', new OutboundMessage(
                    to: $email, body: nl2br(e($text)), subject: __('insights.morning.subject', ['branch' => $report['branch'], 'date' => $report['date']], $locale),
                    locale: $locale, meta: ['type' => 'insights_morning_report', 'branch_id' => $branch->id],
                ))->ok;
            }
            if (($settings['whatsapp'] ?? false) && ($phone = InsightSettings::get('alerts.whatsapp_to', null, $tenant) ?: $owner?->phone ?: $tenant->phone)) {
                $channels['whatsapp'] = $this->messaging->send('whatsapp', new OutboundMessage(
                    to: $phone, body: $text, locale: $locale, meta: ['type' => 'insights_morning_report', 'branch_id' => $branch->id],
                ))->ok;
            }
            $delivery->update(['channels' => $channels]);
            $count++;
        }

        return $count;
    }
}
