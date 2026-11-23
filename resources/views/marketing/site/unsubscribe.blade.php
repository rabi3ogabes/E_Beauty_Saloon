<x-layouts.site :title="__('marketing.unsubscribe.title')">
    <section class="container-luxe py-16 sm:py-24">
        <div class="card mx-auto max-w-md p-8 text-center">
            @if ($done)
                <h1 class="font-display text-2xl font-semibold">{{ __('marketing.unsubscribe.done_title') }}</h1>
                <p class="mt-3 text-muted">{{ __('marketing.unsubscribe.done_body', ['salon' => $currentTenant->name]) }}</p>
                <a href="{{ url('/') }}" class="btn-ghost mt-8">{{ __('marketing.unsubscribe.back') }}</a>
            @else
                <h1 class="font-display text-2xl font-semibold">{{ __('marketing.unsubscribe.title') }}</h1>
                <p class="mt-3 text-muted">{{ __('marketing.unsubscribe.body', ['salon' => $currentTenant->name]) }}</p>
                <form method="POST" action="{{ route('marketing.unsubscribe.confirm', $message->token) }}" class="mt-8 space-y-3">
                    @csrf
                    @if ($message->channel)
                        <button name="scope" value="channel" class="btn-ghost w-full">{{ __('marketing.unsubscribe.only_channel', ['channel' => __('marketing.channels.'.$message->channel)]) }}</button>
                    @endif
                    <button name="scope" value="all" class="btn-primary w-full">{{ __('marketing.unsubscribe.all') }}</button>
                </form>
            @endif
        </div>
    </section>
</x-layouts.site>
