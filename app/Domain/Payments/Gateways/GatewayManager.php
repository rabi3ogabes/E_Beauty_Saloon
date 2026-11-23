<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Models\TenantPaymentGateway;
use InvalidArgumentException;

/**
 * Resolves PaymentGateway drivers for tenant gateways (salon's own merchant accounts)
 * and for platform billing (credentials in config/services.php -> platform_gateways).
 */
class GatewayManager
{
    protected static bool $faked = false;

    public static function fake(bool $fake = true): void
    {
        static::$faked = $fake;
        FakeGateway::$refunds = [];
        FakeGateway::$checkouts = [];
    }

    public static function isFaked(): bool
    {
        return static::$faked;
    }

    public function forTenantGateway(TenantPaymentGateway $gateway): PaymentGateway
    {
        return $this->make($gateway->driver, $gateway->credentials ?? [], $gateway->mode);
    }

    public function forPlatform(string $driver): PaymentGateway
    {
        return $this->make($driver, config("services.platform_gateways.$driver", []), config('services.platform_gateways.mode', 'test'));
    }

    public function make(string $driver, array $credentials = [], string $mode = 'test'): PaymentGateway
    {
        if (static::$faked && in_array($driver, TenantPaymentGateway::ONLINE_DRIVERS, true)) {
            return new FakeGateway($credentials, $mode);
        }

        return match ($driver) {
            'sadad' => new SadadGateway($credentials, $mode),
            'skipcash' => new SkipCashGateway($credentials, $mode),
            'myfatoorah' => new MyFatoorahGateway($credentials, $mode),
            'tap' => new TapGateway($credentials, $mode),
            'stripe' => new StripeGateway($credentials, $mode),
            'cash', 'card_terminal', 'bank_transfer', 'cod', 'manual' => new OfflineGateway($driver, $credentials, $mode),
            'fake' => static::$faked || app()->environment(['local', 'testing'])
                ? new FakeGateway($credentials, $mode)
                : throw new InvalidArgumentException('The fake gateway is disabled in this environment.'),
            default => throw new InvalidArgumentException("Unknown payment driver [$driver]"),
        };
    }
}
