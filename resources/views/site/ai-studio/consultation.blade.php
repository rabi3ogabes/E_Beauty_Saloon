<x-layouts.site :title="__('ai_studio.consult.title')">
    <section class="container-luxe py-10 sm:py-16">
        <a href="{{ route('studio.index') }}" class="text-sm text-muted hover:text-ink">← {{ __('ai_studio.look.back') }}</a>
        <p class="eyebrow mt-6">{{ __('ai_studio.modes.consult') }}</p>
        <h1 class="mt-2 text-4xl font-semibold">{{ __('ai_studio.consult.title') }}</h1>

        <dl class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
            @foreach (['face_shape', 'skin_tone', 'undertone', 'hair_texture'] as $key)
                <div class="card p-5">
                    <dt class="text-xs text-muted">{{ __('ai_studio.consult.'.$key) }}</dt>
                    <dd class="mt-1 font-display text-lg">{{ $analysis[$key] ?? '—' }}</dd>
                </div>
            @endforeach
        </dl>
        @if (! empty($analysis['notes']))<p class="mt-6 max-w-2xl text-muted">{{ $analysis['notes'] }}</p>@endif

        <h2 class="mt-12 text-2xl font-semibold">{{ __('ai_studio.consult.picks') }}</h2>
        <div class="mt-6 grid gap-6 md:grid-cols-3">
            @forelse ($recommendations as $rec)
                <article class="card flex flex-col p-6" data-reveal>
                    <p class="eyebrow">{{ __('ai_studio.categories.'.$rec['style']->category) }}</p>
                    <h3 class="mt-2 text-xl font-semibold">{{ $rec['style']->name }}</h3>
                    <p class="mt-3 flex-1 text-sm text-muted">{{ $rec['reason'] }}</p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        @if (tenant()->hasModule('booking') && ($url = \App\Domain\AIStudio\Support\LookPresenter::bookUrl($rec['style'])))
                            <a href="{{ $url }}" class="btn-primary">{{ __('ai_studio.look.book') }}</a>
                        @endif
                        <a href="{{ route('studio.index') }}#style-{{ $rec['style']->id }}" class="btn-ghost">{{ __('ai_studio.consult.try_it') }}</a>
                    </div>
                </article>
            @empty
                <p class="text-muted">{{ __('ai_studio.consult.none') }}</p>
            @endforelse
        </div>
    </section>
</x-layouts.site>
