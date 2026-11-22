<?php

namespace App\Http\Controllers\Payments;

use App\Domain\Payments\Actions\SettlePayment;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Domain\Payments\Models\Payment;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PaymentFlowController extends Controller
{
    /** Auto-submitting form for providers that require a browser POST (e.g. SADAD). */
    public function redirect(Request $request)
    {
        $data = decrypt($request->query('to'));

        return view('payments.redirect', ['action' => $data['action'], 'fields' => $data['fields']]);
    }

    /** Client lands here after the hosted page. We re-check status with the provider (never trust the redirect). */
    public function return(Payment $payment, GatewayManager $manager, SettlePayment $settle)
    {
        if ($payment->status === 'pending' && $payment->gateway && $payment->provider_reference) {
            try {
                $status = $manager->forTenantGateway($payment->gateway)->status($payment->provider_reference);
                if ($status === 'succeeded') {
                    $settle->succeed($payment);
                } elseif ($status === 'failed') {
                    $settle->fail($payment, 'provider_status');
                }
            } catch (\Throwable) {
                // Webhook will settle it; show pending state.
            }
            $payment->refresh();
        }

        $target = $payment->meta['return_to'] ?? null;

        return $target ? redirect($target) : view('payments.result', ['payment' => $payment]);
    }

    public function fake(string $reference)
    {
        abort_unless(app()->environment(['local', 'testing']) || GatewayManager::isFaked() || config('app.demo'), 404);
        $payment = Payment::where('idempotency_key', $reference)->firstOrFail();

        return view('payments.fake', compact('payment'));
    }

    public function fakeConfirm(Request $request, string $reference, SettlePayment $settle)
    {
        abort_unless(app()->environment(['local', 'testing']) || GatewayManager::isFaked() || config('app.demo'), 404);
        $payment = Payment::where('idempotency_key', $reference)->firstOrFail();
        $request->input('outcome') === 'fail' ? $settle->fail($payment, 'declined') : $settle->succeed($payment, 'fake_'.$reference);

        return redirect()->route('payments.return', $payment->idempotency_key);
    }
}
