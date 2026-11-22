<?php

namespace App\Domain\Api\Support;

use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Models\ClientOtp;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Hash;

/**
 * App Store / Play review sign-in. When the salon settings hold `api.review_phone` and `api.review_code`, that one
 * phone number signs in with that fixed code and no WhatsApp/SMS is sent. Every other number uses the normal OTP.
 *
 * The bypass only *prepares* a one-time code row; verification still runs through VerifyClientOtp, so the review
 * client is created / blocked / audited exactly like a real one. Remove the settings after the review.
 */
class ReviewLogin
{
    public static function matches(?Tenant $tenant, string $phone): bool
    {
        $reviewPhone = (string) $tenant?->setting('api.review_phone', '');
        $reviewCode = (string) $tenant?->setting('api.review_code', '');

        return $reviewPhone !== '' && preg_match('/^\d{6}$/', $reviewCode) === 1
            && Client::normalizePhone($reviewPhone) === Client::normalizePhone($phone);
    }

    public static function codeMatches(?Tenant $tenant, string $phone, string $code): bool
    {
        return self::matches($tenant, $phone) && hash_equals((string) $tenant->setting('api.review_code'), trim($code));
    }

    /** Stores the fixed review code as the phone's current one-time code (10 minutes). */
    public static function prepare(Tenant $tenant, string $phone): ClientOtp
    {
        $phone = Client::normalizePhone($phone);
        ClientOtp::query()->where('phone', $phone)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        activity()->withProperties(['phone' => $phone])->log('api.review_login');

        return ClientOtp::create(['phone' => $phone, 'code_hash' => Hash::make((string) $tenant->setting('api.review_code')), 'expires_at' => now()->addMinutes(10)]);
    }
}
