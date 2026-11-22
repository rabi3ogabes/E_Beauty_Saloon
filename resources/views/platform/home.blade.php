<x-layouts.platform>
    <section class="container-luxe py-20 text-center md:py-28">
        <p class="eyebrow" data-reveal>{{ __('platform.hero.eyebrow') }}</p>
        <h1 class="mx-auto mt-5 max-w-3xl text-4xl leading-[1.08] font-semibold sm:text-6xl" data-reveal>{{ __('platform.hero.title') }}</h1>
        <p class="mx-auto mt-6 max-w-2xl text-lg text-muted" data-reveal>{{ __('platform.hero.body') }}</p>
        <div class="mt-10 flex justify-center gap-3" data-reveal>
            <a href="{{ url('/signup') }}" class="btn-primary">{{ __('platform.cta.start_trial') }}</a>
            <a href="{{ url('/pricing') }}" class="btn-ghost">{{ __('platform.cta.see_pricing') }}</a>
        </div>
    </section>
    <section id="modules" class="container-luxe grid gap-5 pb-10 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($modules as $module)
            <div class="card p-7" data-reveal>
                <p class="text-lg font-semibold">{{ $module->name }}</p>
                <p class="mt-2 text-sm text-muted">{{ $module->description }}</p>
            </div>
        @endforeach
    </section>
</x-layouts.platform>
