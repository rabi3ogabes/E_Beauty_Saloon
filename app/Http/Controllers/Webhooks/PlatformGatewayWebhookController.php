<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Billing\Actions\MarkInvoicePaid;
use App\Domain\Billing\Models\PlatformPayment;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Payments\Models\PaymentWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/** Webhooks for subscription payments collected by the platform. */
class PlatformGatewayWebhookController extends Controller
{
    public function __invoke(Request $request, string $driver, GatewayManager $manager, MarkInvoicePaid $markPaid)
    {
        try {
            $event = $manager->forPlatform($driver)->parseWebhook($request);
        } catch (\Throwable) {
            return response()->json(['ok' => false], 400);
        }

        try {
            $log = PaymentWebhook::create(['driver' => 'platform:'.$driver, 'event_id' => $event->eventId, 'event_type' => $event->type, 'payload' => $event->raw]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $pending = PlatformPayment::where('idempotency_key', $event->merchantReference)->where('status', 'pending')->first();
        if ($pending && $event->status === 'succeeded') {
            $pending->update(['status' => 'superseded']);
            $markPaid->handle($pending->invoice, $driver, $event->reference, 'webhook:'.$event->eventId, $pending->amount);
        } elseif ($pending && $event->status === 'failed') {
            $pending->update(['status' => 'failed', 'failure_reason' => $event->type]);
        }
        $log->update(['status' => 'processed', 'processed_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
