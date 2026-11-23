@php($local = $booking->starts_at->copy()->setTimezone($tz))
<div class="card p-6 sm:p-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs text-muted">{{ __('booking.reference') }}</p>
            <p class="font-mono text-2xl tracking-[0.2em]" dir="ltr">{{ $booking->reference }}</p>
        </div>
        <span @class([
            'chip text-xs',
            'border-success/40 text-success' => in_array($booking->status->value, ['confirmed', 'checked_in', 'in_service', 'completed'], true),
            'border-warning/40 text-warning' => $booking->status->value === 'pending_payment',
            'border-danger/40 text-danger' => in_array($booking->status->value, ['cancelled_by_client', 'cancelled_by_salon', 'no_show'], true),
        ])>{{ $booking->status->getLabel() }}</span>
    </div>

    <dl class="mt-6 grid gap-5 sm:grid-cols-2">
        <div>
            <dt class="text-xs text-muted">{{ __('booking.when') }}</dt>
            <dd class="mt-1 font-display text-xl">{{ $local->translatedFormat('l j F Y') }}</dd>
            <dd class="text-sm" dir="ltr">{{ $local->format('H:i') }} – {{ $booking->ends_at->copy()->setTimezone($tz)->format('H:i') }}</dd>
        </div>
        <div>
            <dt class="text-xs text-muted">{{ __('booking.where') }}</dt>
            <dd class="mt-1 font-medium">{{ $booking->branch->name }}</dd>
            @if ($booking->branch->address)<dd class="text-sm text-muted">{{ $booking->branch->address }}</dd>@endif
        </div>
    </dl>

    <ul class="mt-6 divide-y hairline border-y hairline">
        @foreach ($booking->items as $item)
            <li class="flex items-center justify-between gap-4 py-3">
                <div class="min-w-0">
                    <p class="font-medium">{{ $item->service?->name }}@if ($item->guest_name) <span class="text-sm text-muted">· {{ $item->guest_name }}</span>@endif</p>
                    <p class="text-xs text-muted"><span dir="ltr">{{ $item->starts_at->copy()->setTimezone($tz)->format('H:i') }}</span> · {{ $item->duration_minutes }} {{ __('salon.ui.minutes') }}@if ($item->staff) · {{ $item->staff->name }}@endif</p>
                </div>
                <p class="shrink-0 text-sm">{{ money($item->price, $booking->currency) }}</p>
            </li>
        @endforeach
    </ul>

    <dl class="mt-4 space-y-1 text-sm">
        <div class="flex justify-between"><dt class="text-muted">{{ __('booking.flow.total') }}</dt><dd class="font-display text-lg">{{ money($booking->total, $booking->currency) }}</dd></div>
        @if ($booking->amount_paid > 0)
            <div class="flex justify-between"><dt class="text-muted">{{ __('booking.paid_online') }}</dt><dd class="text-success">{{ money($booking->amount_paid, $booking->currency) }}</dd></div>
            <div class="flex justify-between"><dt class="text-muted">{{ __('booking.balance_due') }}</dt><dd>{{ money($booking->balanceDue(), $booking->currency) }}</dd></div>
        @endif
        @if ($booking->fee_charged > 0)
            <div class="flex justify-between"><dt class="text-muted">{{ __('booking.fee_charged') }}</dt><dd>{{ money($booking->fee_charged, $booking->currency) }}</dd></div>
        @endif
    </dl>
</div>
