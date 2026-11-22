<?php

namespace App\Domain\Booking\Support;

use App\Domain\Booking\Availability\ServiceLine;
use App\Domain\Booking\Availability\Slot;
use App\Domain\Booking\Models\Booking;
use App\Domain\Payments\Actions\RefundPayment;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\TenantPaymentGateway;

/**
 * Money helpers for bookings: which payment options a client may pick, the online gateway to use,
 * the idempotent "amount paid" (sum of settled payments minus refunds) and refunds of collected money.
 */
class BookingPayments
{
    public function __construct(protected RefundPayment $refunds) {}

    /** Online gateway for a context: deposits → "deposits" (falls back to "booking"), full → "booking". */
    public function gateway(string $mode, ?int $branchId): ?TenantPaymentGateway
    {
        $contexts = $mode === 'deposit' ? ['deposits', 'booking'] : ['booking'];
        foreach ($contexts as $context) {
            $gateway = TenantPaymentGateway::for($context, $branchId)->first(fn (TenantPaymentGateway $g) => $g->isOnline());
            if ($gateway) {
                return $gateway;
            }
        }

        return null;
    }

    /**
     * Payment choices for a quote: deposit (when services require one), full prepayment, or pay at salon.
     * A required deposit can only be skipped when no online gateway is configured.
     *
     * @return list<string>
     */
    public function options(int $total, int $deposit, ?int $branchId): array
    {
        $depositGateway = $deposit > 0 ? $this->gateway('deposit', $branchId) : null;
        $fullGateway = $total > 0 ? $this->gateway('full', $branchId) : null;
        $options = [];
        if ($depositGateway) {
            $options[] = 'deposit';
        }
        if ($fullGateway) {
            $options[] = 'full';
        }
        if ($deposit === 0 || ! $depositGateway) {
            $options[] = 'pay_at_salon';
        }

        return $options;
    }

    public function defaultOption(array $options): string
    {
        return $options[0] ?? 'pay_at_salon';
    }

    /**
     * Deposit for a quoted slot: sum of each service's deposit rule on its (staff-specific) price.
     *
     * @param  list<ServiceLine>  $lines
     */
    public function depositFor(array $lines, Slot $slot): int
    {
        $deposit = 0;
        foreach ($slot->items as $item) {
            $deposit += $lines[$item['line']]->service->depositFor($item['price']);
        }

        return min($deposit, $slot->price());
    }

    /** Amount the client must pay online now for a preference. */
    public function dueNow(Booking $booking, string $preference): int
    {
        return match ($preference) {
            'deposit' => max(0, $booking->deposit_required - $booking->amount_paid),
            'full' => $booking->balanceDue(),
            default => 0,
        };
    }

    /** Settled payments minus refunds — safe to call any number of times. */
    public function recalculatePaid(Booking $booking): int
    {
        $paid = (int) Payment::withoutGlobalScopes()
            ->where('payable_type', $booking->getMorphClass())->where('payable_id', $booking->id)
            ->whereIn('status', ['succeeded', 'partially_refunded', 'refunded'])
            ->sum('amount');
        $paid = max(0, $paid);
        if ($paid !== (int) $booking->amount_paid) {
            $booking->forceFill(['amount_paid' => $paid])->save();
        }

        return $paid;
    }

    /** Refund up to $amount of the booking's collected payments (newest first). Returns the amount refunded. */
    public function refund(Booking $booking, int $amount, string $reason): int
    {
        if ($amount <= 0) {
            return 0;
        }
        $refunded = 0;
        $payments = Payment::withoutGlobalScopes()
            ->where('payable_type', $booking->getMorphClass())->where('payable_id', $booking->id)
            ->where('amount', '>', 0)->whereIn('status', ['succeeded', 'partially_refunded'])
            ->latest('id')->get();
        foreach ($payments as $payment) {
            $part = min($amount - $refunded, $payment->refundable());
            if ($part <= 0) {
                continue;
            }
            try {
                $this->refunds->handle($payment, $part, reason: $reason);
                $refunded += $part;
            } catch (\Throwable $e) {
                activity()->performedOn($booking)->withProperties(['payment_id' => $payment->id, 'amount' => $part, 'error' => $e->getMessage()])->log('booking.refund_failed');
            }
            if ($refunded >= $amount) {
                break;
            }
        }
        $this->recalculatePaid($booking);

        return $refunded;
    }

    /** Fee kept from the money collected online (percent 0–100 of amount paid). */
    public function feeFromPaid(Booking $booking, int $percent): int
    {
        return (int) min($booking->amount_paid, round($booking->amount_paid * max(0, min(100, $percent)) / 100));
    }
}
