<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Availability\AvailabilityEngine;
use App\Domain\Booking\Availability\LineBuilder;
use App\Domain\Booking\Availability\ServiceLine;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Events\BookingCheckedIn;
use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\Booking\Events\BookingCreated;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SlotHold;
use App\Domain\Booking\Support\BookingLock;
use App\Domain\Booking\Support\BookingPayments;
use App\Domain\Booking\Support\BookingTransitions;
use App\Domain\Branches\Models\Branch;
use App\Domain\CRM\Models\Client;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates a booking after re-validating the slot with the engine (inside the tenant booking lock + a transaction).
 * Same action for the website, dashboard, walk-ins and the API.
 *
 * $data keys: branch|branch_id, items (raw) | lines (ServiceLine[]), starts_at (UTC), client|client_id,
 * source, payment_preference (deposit|full|pay_at_salon), hold_token, client_notes, internal_notes,
 * campaign_id, utm_source, look_reference, group_id, recurring_parent_id, recurrence_rule, status,
 * online (bool — public channel rules), enforce_notice (bool), created_by.
 */
class CreateBooking
{
    public function __construct(
        protected AvailabilityEngine $engine,
        protected LineBuilder $lineBuilder,
        protected BookingLock $lock,
        protected BookingTransitions $transitions,
        protected BookingPayments $payments,
    ) {}

    public function handle(array $data): Booking
    {
        abort_if(tenant()?->isReadOnly(), 423);

        $branch = $data['branch'] ?? Branch::findOrFail($data['branch_id'] ?? 0);
        $source = $data['source'] ?? 'website';
        $online = $data['online'] ?? in_array($source, ['website', 'app'], true);
        if ($online && ! $branch->booking_enabled) {
            throw new DomainException(__('booking.errors.branch_unavailable'));
        }
        /** @var list<ServiceLine> $lines */
        $lines = $data['lines'] ?? $this->lineBuilder->build($branch, $data['items'] ?? [], $online);
        $start = CarbonImmutable::parse($data['starts_at'])->utc();
        $client = $data['client'] ?? (! empty($data['client_id']) ? Client::findOrFail($data['client_id']) : null);
        if ($client?->is_blocked) {
            throw new DomainException(__('booking.errors.client_blocked'));
        }

        return $this->lock->run((int) $branch->tenant_id, fn () => DB::transaction(function () use ($data, $branch, $lines, $start, $client, $source, $online) {
            $slot = $this->engine->quote($branch, $lines, $start, [
                'online' => $online,
                'enforce_notice' => $data['enforce_notice'] ?? $online,
                'enforce_max_days' => $online,
                'ignore_hold_token' => $data['hold_token'] ?? null,
            ]);
            if (! $slot) {
                throw new DomainException(__('booking.errors.slot_taken'));
            }

            $subtotal = $slot->price();
            $deposit = $this->payments->depositFor($lines, $slot);

            $options = $this->payments->options($subtotal, $deposit, $branch->id);
            $preference = $data['payment_preference'] ?? ($online ? $this->payments->defaultOption($options) : 'pay_at_salon');
            if ($online && ! in_array($preference, $options, true)) {
                throw new DomainException(__('booking.errors.payment_option'));
            }
            $needsPayment = in_array($preference, ['deposit', 'full'], true) && ($preference === 'full' ? $subtotal : $deposit) > 0;
            $status = isset($data['status'])
                ? BookingStatus::from(is_string($data['status']) ? $data['status'] : $data['status']->value)
                : ($needsPayment ? BookingStatus::PendingPayment : BookingStatus::Confirmed);

            $booking = Booking::create([
                'branch_id' => $branch->id,
                'client_id' => $client?->id,
                'status' => $status,
                'source' => $source,
                'starts_at' => $slot->startsAt(),
                'ends_at' => $slot->endsAt(),
                'subtotal' => $subtotal,
                'discount' => 0,
                'total' => $subtotal,
                'deposit_required' => $deposit,
                'currency' => tenant()?->currency ?? 'QAR',
                'payment_preference' => $preference,
                'group_id' => $data['group_id'] ?? null,
                'recurring_parent_id' => $data['recurring_parent_id'] ?? null,
                'recurrence_rule' => $data['recurrence_rule'] ?? null,
                'client_notes' => $data['client_notes'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                'campaign_id' => ! empty($data['campaign_id']) && is_numeric($data['campaign_id']) ? (int) $data['campaign_id'] : null,
                'utm_source' => isset($data['utm_source']) ? mb_substr((string) $data['utm_source'], 0, 60) : null,
                'look_reference' => isset($data['look_reference']) ? mb_substr((string) $data['look_reference'], 0, 190) : null,
                'created_by' => $data['created_by'] ?? auth('web')->id(),
                'confirmed_at' => in_array($status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true) ? now() : null,
                'checked_in_at' => $status === BookingStatus::CheckedIn ? now() : null,
            ]);

            foreach ($slot->items as $sort => $item) {
                $booking->items()->create([
                    'service_id' => $item['service_id'],
                    'service_variant_id' => $item['variant_id'],
                    'staff_id' => $item['staff_id'],
                    'resource_id' => $item['resource_id'],
                    'addon_ids' => $item['addon_ids'] ?: null,
                    'starts_at' => CarbonImmutable::createFromTimestampUTC($item['starts_at']),
                    'ends_at' => CarbonImmutable::createFromTimestampUTC($item['ends_at']),
                    'buffer_before' => $item['buffer_before'],
                    'buffer_after' => $item['buffer_after'],
                    'price' => $item['price'],
                    'duration_minutes' => $item['duration_minutes'],
                    'guest_name' => $lines[$item['line']]->guestName,
                    'sort' => $sort,
                ]);
            }

            if (! empty($data['hold_token'])) {
                SlotHold::where('token', 'like', $data['hold_token'].'.%')->whereNull('booking_id')->update(['booking_id' => $booking->id]);
            }

            $this->transitions->record($booking, null, $status, $data['history_note'] ?? __('booking.history.created', ['source' => __('booking.sources.'.$source)]));
            $this->transitions->log($booking, 'created', ['source' => $source, 'total' => $subtotal, 'deposit' => $deposit]);

            $booking->setRelation('branch', $branch);
            BookingCreated::dispatch($booking);
            if (in_array($status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true)) {
                BookingConfirmed::dispatch($booking);
            }
            if ($status === BookingStatus::CheckedIn) {
                BookingCheckedIn::dispatch($booking);
            }

            return $booking;
        }));
    }
}
