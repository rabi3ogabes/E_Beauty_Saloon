<?php

namespace App\Domain\Booking\Notifications;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WaitlistEntry;
use App\Domain\Booking\Support\BookingLinks;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\MessagingManager;
use Carbon\CarbonInterface;

/**
 * Client messages for the booking lifecycle: WhatsApp (fallback SMS) + email when known,
 * in the client's language, with every time shown in the BRANCH timezone.
 *
 * Types: confirmation, reminder_24h, reminder_2h, rescheduled, cancelled, thank_you.
 */
class BookingNotifier
{
    public const TYPES = ['confirmation', 'reminder_24h', 'reminder_2h', 'rescheduled', 'cancelled', 'thank_you'];

    public function __construct(protected MessagingManager $messaging) {}

    /** @return ?string channel used for the phone message */
    public function send(Booking $booking, string $type): ?string
    {
        try {
            return $this->compose($booking, $type);
        } catch (\Throwable $e) {
            report($e); // never break a booking because a message failed

            return null;
        }
    }

    protected function compose(Booking $booking, string $type): ?string
    {
        $booking->loadMissing(['client', 'branch', 'items.service', 'items.staff']);
        $client = $booking->client;
        if (! $client || $client->is_blocked) {
            return null;
        }
        $locale = $this->locale($client);
        $vars = $this->vars($booking, $locale);

        $body = __("booking.notify.{$type}.body", $vars, $locale);
        if (in_array($type, ['reminder_24h', 'reminder_2h'], true)) {
            $body .= "\n\n".__('booking.notify.running_late', $vars, $locale);
        }
        if ($type === 'thank_you' && $vars['rebook_hint']) {
            $body .= "\n\n".$vars['rebook_hint'];
        }
        $buttons = $this->buttons($booking, $type, $locale, $vars);

        return $this->deliver($client, $type, $body, __("booking.notify.{$type}.subject", $vars, $locale), $buttons, $locale, [
            'booking_id' => $booking->id, 'type' => 'booking_'.$type, 'template_vars' => [$vars['name'], $vars['date'], $vars['time'], $vars['reference']],
        ]);
    }

    public function waitlistOffer(WaitlistEntry $entry, CarbonInterface $startsAt, string $claimUrl): ?string
    {
        $entry->loadMissing(['client', 'branch']);
        $client = $entry->client;
        if (! $client) {
            return null;
        }
        $locale = $this->locale($client);
        $tz = $entry->branch->timezone;
        $local = $startsAt->copy()->setTimezone($tz)->locale($locale);
        $vars = [
            'name' => $client->firstName(), 'salon' => tenant()?->name, 'branch' => $entry->branch->getTranslation('name', $locale, false) ?: $entry->branch->name,
            'date' => $local->translatedFormat('l j F'), 'time' => $local->format('H:i'), 'url' => $claimUrl,
            'services' => Service::whereIn('id', $entry->service_ids ?? [])->get()->map(fn ($s) => $s->getTranslation('name', $locale, false) ?: $s->name)->implode(' + '),
        ];

        return $this->deliver($client, 'waitlist_offer', __('booking.notify.waitlist_offer.body', $vars, $locale), __('booking.notify.waitlist_offer.subject', $vars, $locale),
            [['type' => 'url', 'text' => __('booking.notify.claim', [], $locale), 'url' => $claimUrl]], $locale, ['waitlist_entry_id' => $entry->id, 'type' => 'booking_waitlist_offer'], whatsappOnly: true);
    }

    protected function deliver(Client $client, string $type, string $body, string $subject, array $buttons, string $locale, array $meta, bool $whatsappOnly = false): ?string
    {
        $channel = null;
        try {
            [$channel] = $this->messaging->sendWithFallback($whatsappOnly ? ['whatsapp', 'sms'] : ['whatsapp', 'sms'], fn ($ch) => $client->phone ? new OutboundMessage(
                to: $client->phone,
                body: $ch === 'sms' ? $this->plain($body, $buttons) : $body,
                template: $ch === 'whatsapp' ? 'booking_'.$type : null,
                variables: $meta['template_vars'] ?? [],
                buttons: $ch === 'whatsapp' ? $buttons : [],
                locale: $locale,
                meta: $meta,
            ) : null);

            if ($client->email && ! $whatsappOnly) {
                $this->messaging->send('email', new OutboundMessage(to: $client->email, body: $body, subject: $subject, buttons: $buttons, locale: $locale, meta: $meta));
            }
        } catch (\Throwable $e) {
            report($e); // never break a booking because a message failed
        }

        return $channel;
    }

    protected function vars(Booking $booking, string $locale): array
    {
        $tz = $booking->branch?->timezone ?? config('salon.defaults.timezone');
        $local = $booking->starts_at->copy()->setTimezone($tz)->locale($locale);
        $services = $booking->items->map(fn ($i) => $i->service?->getTranslation('name', $locale, false) ?: $i->service?->name)->filter()->implode(' + ');
        $staff = $booking->items->map(fn ($i) => $i->staff?->getTranslation('name', $locale, false) ?: $i->staff?->name)->filter()->unique()->implode(', ');
        $rebookDays = $booking->items->map(fn ($i) => $i->service?->rebook_after_days)->filter()->min();

        return [
            'name' => $booking->client?->firstName(),
            'salon' => tenant()?->name,
            'branch' => $booking->branch?->getTranslation('name', $locale, false) ?: $booking->branch?->name,
            'address' => $booking->branch?->getTranslation('address', $locale, false) ?: '',
            'date' => $local->translatedFormat('l j F'),
            'time' => $local->format('H:i'),
            'services' => $services,
            'staff' => $staff,
            'reference' => $booking->reference,
            'total' => money($booking->total, $booking->currency),
            'paid' => money($booking->amount_paid, $booking->currency),
            'fee' => money($booking->fee_charged, $booking->currency),
            'manage_url' => BookingLinks::manage($booking),
            'review_url' => BookingLinks::review($booking),
            'rebook_url' => BookingLinks::rebook($booking),
            'rebook_hint' => $rebookDays ? __('booking.notify.rebook_hint', [
                'date' => now($tz)->addDays($rebookDays)->locale($locale)->translatedFormat('j F'), 'url' => BookingLinks::rebook($booking),
            ], $locale) : null,
        ];
    }

    protected function buttons(Booking $booking, string $type, string $locale, array $vars): array
    {
        return match ($type) {
            'thank_you' => [
                ['type' => 'url', 'text' => __('booking.notify.leave_review', [], $locale), 'url' => $vars['review_url']],
                ['type' => 'url', 'text' => __('booking.notify.rebook', [], $locale), 'url' => $vars['rebook_url']],
            ],
            'cancelled' => [['type' => 'url', 'text' => __('booking.notify.rebook', [], $locale), 'url' => $vars['rebook_url']]],
            default => [['type' => 'url', 'text' => __('booking.notify.manage', [], $locale), 'url' => $vars['manage_url']]],
        };
    }

    protected function plain(string $body, array $buttons): string
    {
        $links = collect($buttons)->map(fn ($b) => ($b['text'] ?? '').': '.($b['url'] ?? ''))->implode("\n");

        return trim($body."\n".$links);
    }

    protected function locale(Client $client): string
    {
        $locale = $client->locale ?: (tenant()?->default_locale ?? 'en');

        return in_array($locale, ['en', 'ar'], true) ? $locale : 'en';
    }
}
