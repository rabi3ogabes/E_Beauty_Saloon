<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Availability\AvailabilityEngine;
use App\Domain\Booking\Availability\LineBuilder;
use App\Domain\Booking\Availability\ServiceLine;
use App\Domain\Booking\Availability\Slot;
use App\Domain\Booking\Models\SlotHold;
use App\Domain\Booking\Support\BookingLock;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Branches\Models\Branch;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Locks a slot during checkout (tenant setting booking.hold_minutes, default 10).
 * Cache::lock + fresh engine re-check + insert inside a transaction: a second client asking for the same
 * staff/time gets "just taken". One hold row per service (staff + primary resource) and per extra resource;
 * rows share the token prefix "{token}." so the holder's own holds are ignored when they book.
 *
 * @return array{token: string, expires_at: CarbonImmutable, slot: Slot}
 */
class HoldSlot
{
    public function __construct(protected AvailabilityEngine $engine, protected LineBuilder $lineBuilder, protected BookingLock $lock) {}

    public function handle(Branch $branch, array $itemsOrLines, CarbonImmutable|string $start, ?string $replaceToken = null, bool $online = true, ?int $minutes = null, ?string $token = null): array
    {
        abort_if(tenant()?->isReadOnly(), 423);

        $lines = isset($itemsOrLines[0]) && $itemsOrLines[0] instanceof ServiceLine
            ? $itemsOrLines : $this->lineBuilder->build($branch, $itemsOrLines, $online);
        $start = CarbonImmutable::parse($start)->utc();

        return $this->lock->run((int) $branch->tenant_id, fn () => DB::transaction(function () use ($branch, $lines, $start, $replaceToken, $online, $minutes, $token) {
            if ($replaceToken) {
                SlotHold::where('token', 'like', $replaceToken.'.%')->whereNull('booking_id')->delete();
            }

            $slot = $this->engine->quote($branch, $lines, $start, ['online' => $online, 'enforce_notice' => $online, 'enforce_max_days' => $online]);
            if (! $slot) {
                throw new DomainException(__('booking.errors.slot_taken'));
            }

            $token ??= Str::random(40);
            $expires = CarbonImmutable::now()->addMinutes($minutes ?? max(1, BookingSettings::get('hold_minutes')));
            foreach ($slot->items as $i => $item) {
                $from = CarbonImmutable::createFromTimestampUTC($item['starts_at'] - $item['buffer_before'] * 60);
                $to = CarbonImmutable::createFromTimestampUTC($item['ends_at'] + $item['buffer_after'] * 60);
                foreach (array_values($item['resource_ids'] ?: [null]) as $j => $resourceId) {
                    SlotHold::create([
                        'branch_id' => $branch->id,
                        'staff_id' => $j === 0 ? $item['staff_id'] : null,
                        'resource_id' => $resourceId,
                        'starts_at' => $from,
                        'ends_at' => $to,
                        'token' => $token.'.'.$i.($j ? '.'.$j : ''),
                        'expires_at' => $expires,
                    ]);
                }
            }

            return ['token' => $token, 'expires_at' => $expires, 'slot' => $slot];
        }));
    }

    public function release(string $token): void
    {
        SlotHold::where('token', 'like', $token.'.%')->whereNull('booking_id')->delete();
    }
}
