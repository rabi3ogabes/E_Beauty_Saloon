<?php

namespace App\Domain\Marketing\Support;

/**
 * Loyalty & referral rules stored in tenant settings (read by the core LoyaltyLedger too):
 *   loyalty.enabled                  bool (default true)
 *   loyalty.points_per_currency_unit points per 1 QAR spent (default 1)
 *   loyalty.point_value              minor units per point when redeeming (default 5 = 0.05 QAR)
 *   loyalty.min_redeem_points        minimum points per redemption (default 100)
 *   loyalty.tiers                    [{key, min_points, points_multiplier, perks: {en, ar}}]
 *   loyalty.referral                 {referrer_points: 500, friend_discount_bp: 1500, friend_coupon_days: 60}
 */
final class LoyaltySettings
{
    public const DEFAULT_TIERS = [
        ['key' => 'silver', 'min_points' => 0, 'points_multiplier' => 1, 'perks' => ['en' => 'Birthday treat · member offers', 'ar' => 'هدية عيد الميلاد · عروض الأعضاء']],
        ['key' => 'gold', 'min_points' => 2000, 'points_multiplier' => 1.25, 'perks' => ['en' => '1.25× points · complimentary blow-dry upgrade', 'ar' => 'نقاط ×1.25 · ترقية استشوار مجانية']],
        ['key' => 'black', 'min_points' => 6000, 'points_multiplier' => 1.5, 'perks' => ['en' => '1.5× points · priority booking · private events', 'ar' => 'نقاط ×1.5 · أولوية الحجز · فعاليات خاصة']],
    ];

    public static function enabled(): bool
    {
        return (bool) (tenant()?->setting('loyalty.enabled', true) ?? true);
    }

    public static function tiers(): array
    {
        $tiers = tenant()?->setting('loyalty.tiers') ?: self::DEFAULT_TIERS;

        return collect($tiers)->sortBy('min_points')->values()->all();
    }

    public static function tier(string $key): array
    {
        return collect(self::tiers())->firstWhere('key', $key) ?? ['key' => $key, 'min_points' => 0, 'points_multiplier' => 1];
    }

    public static function nextTier(int $lifetimePoints): ?array
    {
        return collect(self::tiers())->first(fn ($t) => $t['min_points'] > $lifetimePoints);
    }

    public static function referral(string $key, mixed $default = null): mixed
    {
        $defaults = ['referrer_points' => 500, 'friend_discount_bp' => 1500, 'friend_coupon_days' => 60];

        return tenant()?->setting('loyalty.referral.'.$key, $defaults[$key] ?? $default) ?? ($defaults[$key] ?? $default);
    }
}
