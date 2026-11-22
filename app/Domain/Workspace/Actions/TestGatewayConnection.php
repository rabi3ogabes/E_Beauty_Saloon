<?php

namespace App\Domain\Workspace\Actions;

use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Payments\Models\TenantPaymentGateway;

/** "Test connection" button: asks the provider and stores last_tested_at / last_test_ok. Returns [ok, message]. */
class TestGatewayConnection
{
    public function __construct(protected GatewayManager $manager) {}

    public function handle(TenantPaymentGateway $gateway): array
    {
        try {
            [$ok, $message] = $this->manager->forTenantGateway($gateway)->testConnection();
        } catch (\Throwable $e) {
            report($e);
            [$ok, $message] = [false, __('salon.payments.test_unreachable')];
        }

        $gateway->forceFill(['last_tested_at' => now(), 'last_test_ok' => (bool) $ok])->save();
        activity()->performedOn($gateway)->causedBy(auth()->user())->withProperties(['ok' => (bool) $ok])->log('payments.gateway_tested');

        return [(bool) $ok, (string) $message];
    }
}
