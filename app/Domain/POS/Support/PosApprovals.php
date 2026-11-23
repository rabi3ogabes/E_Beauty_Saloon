<?php

namespace App\Domain\POS\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Server-issued, single-use manager approvals (discounts, voids...). The client only ever holds an
 * opaque token; it can never claim "approved by user #X" itself.
 */
class PosApprovals
{
    public const TTL_SECONDS = 600;

    public static function issue(User $manager, User $cashier, string $purpose = 'discount'): string
    {
        $token = Str::random(48);
        Cache::put(self::key($token), [
            'tenant_id' => tenant()?->id, 'manager_id' => $manager->id, 'cashier_id' => $cashier->id, 'purpose' => $purpose,
        ], self::TTL_SECONDS);

        return $token;
    }

    /** Returns the approving manager id, or null when the token is missing/expired/foreign. Single use. */
    public static function consume(?string $token, User $cashier, string $purpose = 'discount'): ?int
    {
        if (! $token) {
            return null;
        }
        $data = Cache::pull(self::key($token));

        return $data && $data['tenant_id'] === tenant()?->id && $data['cashier_id'] === $cashier->id && $data['purpose'] === $purpose
            ? (int) $data['manager_id'] : null;
    }

    protected static function key(string $token): string
    {
        return 'pos-approval:'.hash('sha256', $token);
    }
}
