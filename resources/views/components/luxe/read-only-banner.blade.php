@if (tenant()?->isReadOnly())
    <div class="flex flex-wrap items-center justify-center gap-3 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200" role="alert">
        <span>{{ __('salon.billing.grace_banner', ['date' => tenant()->grace_ends_at?->toFormattedDayDateString()]) }}</span>
        <a href="{{ url('/dashboard/billing') }}" class="font-semibold underline underline-offset-4">{{ __('salon.billing.pay_now') }}</a>
    </div>
@endif
