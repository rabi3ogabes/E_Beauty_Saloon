<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Payments\Actions\SettlePayment;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentWebhook;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives salon gateway webhooks. Idempotent: (driver, event_id) is unique, so a
 * replayed webhook is acknowledged without being processed twice.
 */
class TenantGatewayWebhookController extends Controller
{
    public function __invoke(Request $request, int $gateway, string $secret, GatewayManager $manager, SettlePayment $settle, TenantContext $context)
    {
        $config = TenantPaymentGateway::withoutGlobalScopes()->findOrFail($gateway);
        abort_unless(hash_equals($config->webhook_secret, $secret), 404);

        return $context->run($config->tenant, function () use ($request, $config, $manager, $settle) {
            try {
                $event = $manager->forTenantGateway($config)->parseWebhook($request);
            } catch (\Throwable $e) {
                Log::warning('Rejected gateway webhook', ['gateway' => $config->id, 'error' => $e->getMessage()]);

                return response()->json(['ok' => false], 400);
            }

            try {
                $log = PaymentWebhook::create([
                    'tenant_id' => $config->tenant_id, 'driver' => $config->driver, 'event_id' => $event->eventId,
                    'event_type' => $event->type, 'payload' => $event->raw,
                ]);
            } catch (UniqueConstraintViolationException) {
                return response()->json(['ok' => true, 'duplicate' => true]);
            }

            $payment = Payment::query()
                ->where('tenant_payment_gateway_id', $config->id)
                ->where(fn ($q) => $q->where('idempotency_key', $event->merchantReference)->orWhere('provider_reference', $event->reference))
                ->first();

            if (! $payment) {
                $log->update(['status' => 'ignored', 'error' => 'payment not found']);

                return response()->json(['ok' => true]);
            }

            if ($event->amount !== null && $event->status === 'succeeded' && $event->amount < $payment->amount) {
                $log->update(['status' => 'failed', 'error' => 'amount mismatch']);

                return response()->json(['ok' => false], 422);
            }

            match ($event->status) {
                'succeeded' => $settle->succeed($payment, $event->reference),
                'failed' => $settle->fail($payment, $event->type),
                default => null,
            };
            $log->update(['status' => 'processed', 'processed_at' => now()]);

            return response()->json(['ok' => true]);
        });
    }
}
