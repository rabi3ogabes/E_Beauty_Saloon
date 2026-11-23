<?php

namespace App\Http\Resources\V1;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\BookingItem;
use App\Domain\Booking\Support\BookingLinks;
use App\Domain\Booking\Support\BookingSettings;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Client-facing booking (mobile `Booking.fromJson`). Times are UTC ISO-8601; `local` carries the branch-local
 * wall clock. `can_cancel` / `can_reschedule` mirror the rules enforced by CancelBooking / RescheduleBooking.
 *
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = $this->currency ?: (tenant()?->currency ?? 'QAR');
        $tz = $this->branch?->timezone ?? tenant()?->timezone ?? 'Asia/Qatar';
        $active = in_array($this->status, [BookingStatus::PendingPayment, BookingStatus::Confirmed], true) && $this->starts_at->isFuture();
        $windowHours = (int) BookingSettings::get('cancellation_window_hours');
        $insideWindow = now()->diffInMinutes($this->starts_at, false) < $windowHours * 60;
        $limit = (int) BookingSettings::get('reschedule_limit');
        $checkout = $this->status === BookingStatus::PendingPayment
            ? $this->payments()->where('status', 'pending')->whereNotNull('checkout_url')->latest('id')->value('checkout_url')
            : null;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->getLabel(),
            'source' => $this->source,
            'starts_at' => $this->starts_at->copy()->utc()->toIso8601ZuluString(),
            'ends_at' => $this->ends_at->copy()->utc()->toIso8601ZuluString(),
            'local' => [
                'date' => $this->starts_at->copy()->setTimezone($tz)->toDateString(),
                'time' => $this->starts_at->copy()->setTimezone($tz)->format('H:i'),
                'timezone' => $tz,
            ],
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'branch' => $this->branch ? ['id' => $this->branch->id, 'name' => $this->branch->name, 'address' => $this->branch->address, 'phone' => $this->branch->phone, 'timezone' => $tz] : null,
            'items' => $this->items->map(fn (BookingItem $i) => [
                'id' => $i->id,
                'service_id' => $i->service_id,
                'service_name' => $i->service?->name,
                'variant_id' => $i->service_variant_id,
                'variant_name' => $i->variant?->name,
                'addon_ids' => array_values((array) ($i->addon_ids ?? [])),
                'staff_id' => $i->staff_id,
                'staff_name' => $i->staff?->name,
                'starts_at' => $i->starts_at->copy()->utc()->toIso8601ZuluString(),
                'ends_at' => $i->ends_at->copy()->utc()->toIso8601ZuluString(),
                'duration_minutes' => $i->duration_minutes,
                'price' => Money::of((int) $i->price, $currency),
            ])->values(),
            'subtotal' => Money::of((int) $this->subtotal, $currency),
            'discount' => Money::of((int) $this->discount, $currency),
            'total' => Money::of((int) $this->total, $currency),
            'payment' => [
                'preference' => $this->payment_preference,
                'status' => $this->paymentStatus(),
                'deposit_required' => Money::of((int) $this->deposit_required, $currency),
                'amount_paid' => Money::of((int) $this->amount_paid, $currency),
                'amount_due' => Money::of($this->balanceDue(), $currency),
                'checkout_url' => $checkout,
            ],
            'notes' => $this->client_notes,
            'can_cancel' => $active,
            'late_cancel_fee' => $active && $insideWindow ? Money::of((int) min($this->amount_paid, round($this->amount_paid * BookingSettings::get('late_cancel_fee_percent') / 100)), $currency) : null,
            'can_reschedule' => $active && ! $insideWindow && $this->reschedule_count < $limit,
            'reschedules_left' => max(0, $limit - (int) $this->reschedule_count),
            'cancellation_window_hours' => $windowHours,
            'links' => [
                'manage' => BookingLinks::manage($this->resource),
                'calendar_ics' => BookingLinks::ics($this->resource),
            ],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    protected function paymentStatus(): string
    {
        return match (true) {
            $this->total > 0 && $this->amount_paid >= $this->total => 'paid',
            $this->amount_paid > 0 => 'partially_paid',
            $this->status === BookingStatus::PendingPayment => 'awaiting_payment',
            default => 'unpaid',
        };
    }
}
