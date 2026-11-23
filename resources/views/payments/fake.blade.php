<x-layouts.site title="Sandbox payment">
    <section class="container-luxe flex justify-center py-20">
        <div class="card w-full max-w-md p-10">
            <p class="eyebrow">Sandbox gateway</p>
            <h1 class="mt-3 text-2xl font-semibold">{{ $payment->payable?->paymentDescription() ?? 'Payment' }}</h1>
            <p class="mt-6 font-display text-4xl">{{ money($payment->amount, $payment->currency) }}</p>
            <p class="mt-2 text-sm text-muted">This page simulates a hosted payment page (SADAD / SkipCash / Stripe) for local testing.</p>
            <form method="POST" action="{{ route('payments.fake.confirm', $payment->idempotency_key) }}" class="mt-8 grid grid-cols-2 gap-3">
                @csrf
                <button name="outcome" value="fail" class="btn-ghost">Decline</button>
                <button name="outcome" value="pay" class="btn-gold">Pay now</button>
            </form>
        </div>
    </section>
</x-layouts.site>
