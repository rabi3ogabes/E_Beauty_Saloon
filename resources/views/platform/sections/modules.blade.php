@php
    $icons = ['booking' => '◷', 'pos' => '▣', 'shop' => '◇', 'marketing' => '✉', 'ai_studio' => '✦', 'website' => '◎', 'loyalty' => '♡'];
@endphp
<section id="modules" class="site-section">
    <div class="container-luxe">
        <div class="mx-auto max-w-2xl text-center" data-reveal>
            <p class="eyebrow">{{ __('platform.nav.features') }}</p>
            <h2 class="mt-3 text-3xl font-semibold sm:text-5xl">{{ $block->t('title') }}</h2>
            @if ($block->t('subtitle'))<p class="mt-4 text-muted">{{ $block->t('subtitle') }}</p>@endif
        </div>
        <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($modules as $module)
                <a href="{{ url('/features/'.$module->key) }}" @class(['card-hover group flex flex-col p-7', 'lg:col-span-2 bg-gradient-to-br from-white to-rose/40' => $module->key === 'ai_studio']) data-reveal>
                    <span class="flex h-11 w-11 items-center justify-center rounded-full bg-gold/15 text-lg text-gold-dark" aria-hidden="true">{{ $icons[$module->key] ?? '✦' }}</span>
                    <h3 class="mt-5 text-xl font-semibold">{{ $module->name }}</h3>
                    <p class="mt-2 flex-1 text-sm leading-relaxed text-muted">{{ $module->description }}</p>
                    <span class="mt-5 text-sm font-medium text-gold-dark">{{ __('salon.ui.learn_more') }} <span class="inline-block transition group-hover:translate-x-1 rtl:group-hover:-translate-x-1">→</span></span>
                </a>
            @endforeach
        </div>
    </div>
</section>
