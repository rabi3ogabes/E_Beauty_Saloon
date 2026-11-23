<?php

namespace App\Domain\CRM\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Models\ClientOtp;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\Hash;

/** Verifies the code and returns the client (creating a profile on first login). */
class VerifyClientOtp
{
    public function handle(string $phone, string $code, ?string $name = null, string $source = 'website'): Client
    {
        $phone = Client::normalizePhone($phone);
        $otp = ClientOtp::where('phone', $phone)->whereNull('consumed_at')->where('expires_at', '>', now())->latest('id')->first();

        if (! $otp || $otp->attempts >= 5) {
            throw new DomainException(__('account.errors.otp_expired'));
        }
        if (! Hash::check(trim($code), $otp->code_hash)) {
            $otp->increment('attempts');
            throw new DomainException(__('account.errors.otp_wrong'));
        }
        $otp->update(['consumed_at' => now()]);

        $client = Client::where('phone', $phone)->first();
        if (! $client) {
            $client = Client::create(['name' => $name ?: __('account.guest'), 'phone' => $phone, 'source' => $source, 'locale' => app()->getLocale()]);
        } elseif ($name && $client->name === __('account.guest')) {
            $client->update(['name' => $name]);
        }

        if ($client->is_blocked) {
            throw new DomainException(__('account.errors.blocked'));
        }

        return $client;
    }
}
