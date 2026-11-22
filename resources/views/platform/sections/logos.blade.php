@php($names = array_values(array_filter((array) $block->get('names', []))))
@if ($names)
<section class="py-10">
    <p class="text-center text-xs uppercase tracking-[0.22em] text-muted">{{ $block->t('title') }}</p>
    <div class="relative mt-6 overflow-hidden [mask-image:linear-gradient(90deg,transparent,#000_12%,#000_88%,transparent)]" aria-hidden="true">
        <div class="marquee">
            @foreach (array_merge($names, $names) as $name)
                <span class="font-display text-2xl whitespace-nowrap text-ink/45">{{ $name }}</span>
            @endforeach
        </div>
    </div>
</section>
@endif
