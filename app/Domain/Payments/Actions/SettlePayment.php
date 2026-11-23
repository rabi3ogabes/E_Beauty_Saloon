<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Contracts\Payable;
use App\Domain\Payments\Events\PaymentFailed;
use App\Domain\Payments\Events\PaymentSucceeded;
use App\Domain\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;

/** Moves a pending payment to succeeded/failed exactly once and notifies its payable. */
class SettlePayment
{
    public function succeed(Payment $payment, ?string $providerReference = null, array $meta = []): Payment
    {
        return DB::transaction(function () use ($payment, $providerReference, $meta) {
            $locked = Payment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== 'pending') {
                return $locked; // idempotent
            }
            $locked->update([
                'status' => 'succeeded',
                'paid_at' => now(),
                'provider_reference' => $providerReference ?? $locked->provider_reference,
                'meta' => array_merge($locked->meta ?? [], $meta),
            ]);

            $payable = $locked->payable()->withoutGlobalScopes()->first();
            if ($payable instanceof Payable) {
                $payable->onPaymentSucceeded($locked);
            }
            DB::afterCommit(fn () => PaymentSucceeded::dispatch($locked));

            return $locked;
        });
    }

    public function fail(Payment $payment, ?string $reason = null): Payment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $locked = Payment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== 'pending') {
                return $locked;
            }
            $locked->update(['status' => 'failed', 'failure_reason' => $reason]);
            $payable = $locked->payable()->withoutGlobalScopes()->first();
            if ($payable instanceof Payable) {
                $payable->onPaymentFailed($locked);
            }
            DB::afterCommit(fn () => PaymentFailed::dispatch($locked));

            return $locked;
        });
    }
}
