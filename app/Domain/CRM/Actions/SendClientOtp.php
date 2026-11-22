<?php

namespace App\Domain\CRM\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Models\ClientOtp;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\MessagingManager;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\Hash;

/** Sends a 6-digit one-time code by WhatsApp (fallback SMS). Valid 10 minutes, 5 attempts. */
class SendClientOtp
{
    public function __construct(protected MessagingManager $messaging) {}

    public function handle(string $phone): ClientOtp
    {
        $phone = Client::normalizePhone($phone);
        if (! preg_match('/^\+\d{8,15}$/', $phone)) {
            throw new DomainException(__('account.errors.phone_invalid'));
        }

        $code = app()->environment('testing') ? '123456' : (string) random_int(100000, 999999);

        ClientOtp::where('phone', $phone)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        $otp = ClientOtp::create(['phone' => $phone, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10)]);

        $text = __('account.otp_message', ['code' => $code, 'salon' => tenant()?->name]);
        $this->messaging->sendWithFallback(['whatsapp', 'sms'], fn ($channel) => new OutboundMessage(
            to: $phone, body: $text, template: $channel === 'whatsapp' ? 'otp_code' : null, variables: [$code], meta: ['type' => 'otp'],
        ));

        if (app()->environment('local')) {
            session()->flash('dev_otp', $code);
        }

        return $otp;
    }
}
