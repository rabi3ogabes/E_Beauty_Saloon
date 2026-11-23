<?php

namespace App\Domain\Insights\Actions;

use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Models\Client;
use App\Domain\Insights\Models\ConsultationForm;
use App\Domain\Insights\Support\InsightSettings;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\MessagingManager;
use Illuminate\Support\Str;

/**
 * Sends a consultation form link to a client (WhatsApp, falling back to SMS) before a booking. Re-uses an open,
 * unexpired form for the same client instead of creating duplicates. Transactional message: no marketing consent needed.
 */
class SendConsultationForm
{
    public function __construct(protected MessagingManager $messaging) {}

    public function handle(Client $client, ?Booking $booking = null, bool $notify = true): ConsultationForm
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        $locale = in_array($client->locale, ['ar', 'en'], true) ? $client->locale : (tenant()->default_locale ?: 'en');

        $form = ConsultationForm::query()->where('client_id', $client->id)->where('status', 'sent')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('id')->first()
            ?? ConsultationForm::create([
                'client_id' => $client->id, 'booking_id' => $booking?->id, 'token' => Str::random(48), 'locale' => $locale,
                'sent_by' => auth()->id(), 'expires_at' => now()->addDays(14),
            ]);
        if ($booking && ! $form->booking_id) {
            $form->update(['booking_id' => $booking->id]);
        }

        if ($notify && $client->phone) {
            $url = rtrim(tenant()->url(), '/').$form->publicPath();
            [$channel] = $this->messaging->sendWithFallback(['whatsapp', 'sms'], fn ($ch) => new OutboundMessage(
                to: $client->phone,
                body: __('insights.consultation.message', ['name' => $client->firstName(), 'salon' => tenant()->name, 'url' => $url], $locale),
                buttons: $ch === 'whatsapp' ? [['type' => 'url', 'text' => __('insights.consultation.open', [], $locale), 'url' => $url]] : [],
                locale: $locale, meta: ['type' => 'consultation_form', 'consultation_form_id' => $form->id],
            ));
            $form->update(['sent_at' => now(), 'channel' => $channel ?? 'link']);
        }
        activity()->performedOn($form)->causedBy(auth()->user())->log('consultation.sent');

        return $form;
    }

    /** Does the client have a signed form that is still valid (settings: valid_months)? */
    public static function hasValidForm(Client $client): bool
    {
        $months = (int) InsightSettings::get('consultation.valid_months', 12);

        return ConsultationForm::query()->where('client_id', $client->id)->where('status', 'signed')
            ->where('signed_at', '>=', now()->subMonths($months))->exists();
    }
}
