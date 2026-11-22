<?php

namespace App\Domain\Insights\Actions;

use App\Domain\Insights\Models\OwnerAlert;
use App\Domain\Insights\Notifications\OwnerAlertNotification;
use App\Domain\Insights\Support\InsightSettings;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\MessagingManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Owner alerts (big sale, VIP check-in, bad review, low stock, failed payment): toggle per type in
 * settings.insights.alerts, de-duplicated by fingerprint, delivered to the owner's notification bell and — when
 * enabled — by WhatsApp. Runs inside the tenant context; never throws into the calling business flow.
 */
class RaiseOwnerAlert
{
    public const LEVELS = ['big_sale' => 'success', 'vip_checkin' => 'info', 'bad_review' => 'danger', 'low_stock' => 'warning', 'failed_payment' => 'danger'];

    public function __construct(protected MessagingManager $messaging) {}

    /** @param bool $bell false when another module already put it in the bell (e.g. marketing's low-rating notice) */
    public function handle(string $type, string $fingerprint, array $params = [], bool $bell = true): ?OwnerAlert
    {
        $tenant = tenant();
        if (! $tenant || ! InsightSettings::get('alerts.'.$type, false)) {
            return null;
        }
        if (OwnerAlert::where('fingerprint', $fingerprint)->exists()) {
            return null;
        }
        try {
            $alert = OwnerAlert::create(['type' => $type, 'fingerprint' => $fingerprint, 'data' => $params]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        try {
            $owner = $tenant->owner;
            $locale = $owner?->locale ?: ($tenant->default_locale ?: 'en');
            $title = __('insights.alerts.'.$type.'.title', $params, $locale);
            $body = __('insights.alerts.'.$type.'.body', $params, $locale);
            if ($bell && $owner) {
                $owner->notify(new OwnerAlertNotification($type, $title, $body, self::LEVELS[$type] ?? 'info'));
            }
            $phone = InsightSettings::get('alerts.whatsapp_to') ?: $owner?->phone ?: $tenant->phone;
            if (InsightSettings::get('alerts.whatsapp', false) && $phone) {
                $ok = $this->messaging->send('whatsapp', new OutboundMessage(to: $phone, body: '🔔 '.$title."\n".$body, locale: $locale,
                    meta: ['type' => 'owner_alert', 'alert' => $type]))->ok;
                $alert->update(['sent_whatsapp' => $ok]);
            }
        } catch (\Throwable $e) {
            report($e); // alerts must never break a sale, booking or payment
        }

        return $alert;
    }
}
