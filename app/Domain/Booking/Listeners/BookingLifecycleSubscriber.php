<?php

namespace App\Domain\Booking\Listeners;

use App\Domain\Booking\Actions\ApplyBookingPayment;
use App\Domain\Booking\Actions\HandleBookingPaymentFailure;
use App\Domain\Booking\Actions\OfferWaitlistSlot;
use App\Domain\Booking\Events\BookingCalendarUpdated;
use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\Booking\Events\BookingCheckedIn;
use App\Domain\Booking\Events\BookingCompleted;
use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\Booking\Events\BookingCreated;
use App\Domain\Booking\Events\BookingEvent;
use App\Domain\Booking\Events\BookingNoShow;
use App\Domain\Booking\Events\BookingRescheduled;
use App\Domain\Booking\Events\BookingServiceStarted;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Notifications\BookingNotifier;
use App\Domain\Payments\Events\PaymentFailed;
use App\Domain\Payments\Events\PaymentSucceeded;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Events\Dispatcher;

/** Booking side-effects: client messages, realtime calendar nudges, waitlist offers, payment settlement. */
class BookingLifecycleSubscriber
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            BookingConfirmed::class => [[self::class, 'confirmed'], [self::class, 'broadcast']],
            BookingRescheduled::class => [[self::class, 'rescheduled'], [self::class, 'broadcast']],
            BookingCancelled::class => [[self::class, 'cancelled'], [self::class, 'broadcast']],
            BookingCreated::class => [[self::class, 'broadcast']],
            BookingCheckedIn::class => [[self::class, 'broadcast']],
            BookingServiceStarted::class => [[self::class, 'broadcast']],
            BookingCompleted::class => [[self::class, 'broadcast']],
            BookingNoShow::class => [[self::class, 'broadcast']],
            PaymentSucceeded::class => [[self::class, 'paymentSucceeded']],
            PaymentFailed::class => [[self::class, 'paymentFailed']],
        ];
    }

    public function confirmed(BookingConfirmed $event): void
    {
        $this->asTenant($event, fn () => app(BookingNotifier::class)->send($event->booking, 'confirmation'));
    }

    public function rescheduled(BookingRescheduled $event): void
    {
        $this->asTenant($event, function () use ($event) {
            app(BookingNotifier::class)->send($event->booking, 'rescheduled');
            $this->offerFreed($event->booking, CarbonImmutable::instance($event->previousStartsAt));
        });
    }

    public function cancelled(BookingCancelled $event): void
    {
        $this->asTenant($event, function () use ($event) {
            if ($event->by !== 'system') {
                app(BookingNotifier::class)->send($event->booking, 'cancelled');
            }
            $this->offerFreed($event->booking, CarbonImmutable::instance($event->booking->starts_at));
        });
    }

    public function broadcast(BookingEvent $event): void
    {
        $b = $event->booking;
        try {
            event(new BookingCalendarUpdated((int) $b->tenant_id, (int) $b->id, (int) $b->branch_id, (string) $b->status?->value, $b->starts_at->toIso8601ZuluString(), class_basename($event)));
        } catch (\Throwable $e) {
            report($e); // realtime is best-effort; calendars poll as a fallback
        }
    }

    public function paymentSucceeded(PaymentSucceeded $event): void
    {
        $this->forPayment($event->payment, fn () => app(ApplyBookingPayment::class)->handle($event->payment));
    }

    public function paymentFailed(PaymentFailed $event): void
    {
        $this->forPayment($event->payment, fn () => app(HandleBookingPaymentFailure::class)->handle($event->payment));
    }

    protected function offerFreed($booking, CarbonImmutable $start): void
    {
        if ($start->isPast()) {
            return;
        }
        $booking->loadMissing('branch');
        try {
            app(OfferWaitlistSlot::class)->handle($booking->branch, $start->setTimezone($booking->branch->timezone)->toDateString(), $start);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function forPayment($payment, \Closure $callback): void
    {
        if ($payment->payable_type !== (new Booking)->getMorphClass()) {
            return;
        }
        $tenant = tenant()?->id === $payment->tenant_id ? tenant() : Tenant::find($payment->tenant_id);
        app(TenantContext::class)->run($tenant, $callback);
    }

    protected function asTenant(BookingEvent $event, \Closure $callback): void
    {
        $tenant = tenant()?->id === $event->booking->tenant_id ? tenant() : Tenant::find($event->booking->tenant_id);
        app(TenantContext::class)->run($tenant, $callback);
    }
}
