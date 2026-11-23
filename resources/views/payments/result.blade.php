<x-layouts.site :title="__('salon.payments.'.($payment->isSucceeded() ? 'success' : ($payment->status === 'pending' ? 'pending' : 'failed')).'_title')">
    @php($state = $payment->isSucceeded() ? 'success' : ($payment->status === 'pending' ? 'pending' : 'failed'))
    <section class="container-luxe flex justify-center py-20">
        <div class="card w-full max-w-md p-10 text-center" @if ($state === 'pending') x-data x-init="setTimeout(() => location.reload(), 4000)" @endif>
            <div @class(['mx-auto flex h-14 w-14 items-center justify-center rounded-full text-2xl',
                'bg-success/10 text-success' => $state === 'success', 'bg-gold/15 text-gold-dark' => $state === 'pending', 'bg-danger/10 text-danger' => $state === 'failed'])>
                {{ $state === 'success' ? '✓' : ($state === 'pending' ? '…' : '!') }}
            </div>
            <h1 class="mt-6 text-2xl font-semibold">{{ __('salon.payments.'.$state.'_title') }}</h1>
            <p class="mt-3 text-muted">{{ __('salon.payments.'.$state.'_body') }}</p>
            <p class="mt-6 font-display text-3xl">{{ money($payment->amount, $payment->currency) }}</p>
            <a href="{{ url('/') }}" class="btn-ghost mt-8">{{ __('salon.ui.back') }}</a>
        </div>
    </section>
</x-layouts.site>
