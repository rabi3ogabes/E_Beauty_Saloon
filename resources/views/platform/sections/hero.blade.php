@php($tag = $first ? 'h1' : 'h2')
<section class="relative overflow-hidden">
    <div class="pointer-events-none absolute inset-x-0 -top-40 -z-10 mx-auto h-[36rem] max-w-5xl rounded-full opacity-60 blur-3xl" style="background: radial-gradient(closest-side, rgba(200,169,106,.35), rgba(233,214,207,.35) 55%, transparent)" aria-hidden="true"></div>
    <div class="container-luxe pt-16 pb-12 text-center md:pt-24">
        <p class="eyebrow">{{ $block->t('eyebrow') }}</p>
        <{{ $tag }} class="mx-auto mt-6 max-w-4xl text-[clamp(2.6rem,6.5vw,5.4rem)] leading-[1.02] font-semibold tracking-tight text-balance">{{ $block->t('title') }}</{{ $tag }}>
        <p class="mx-auto mt-7 max-w-2xl text-lg leading-relaxed text-muted">{{ $block->t('body') }}</p>
        <div class="mt-10 flex flex-wrap justify-center gap-3">
            @if ($block->t('cta_label'))<a href="{{ url($block->t('cta_url', '/signup')) }}" class="btn-primary px-8">{{ $block->t('cta_label') }}</a>@endif
            @if ($block->t('secondary_label'))<a href="{{ url($block->t('secondary_url', '/demo')) }}" class="btn-ghost px-7">{{ $block->t('secondary_label') }}</a>@endif
        </div>
        <p class="mt-5 text-xs text-muted">{{ __('platform.hero.trust') }}</p>
    </div>

    {{-- Product visual: pure HTML/CSS mock (no image request, zero layout shift). --}}
    <div class="container-luxe pb-10">
        <div class="relative mx-auto max-w-5xl">
            @if ($video = $block->get('video_url'))
                <div class="aspect-video overflow-hidden rounded-(--radius-hero) bg-ink shadow-lift">
                    <video src="{{ $video }}" class="h-full w-full object-cover" autoplay muted loop playsinline preload="none" @if ($block->get('image')) poster="{{ asset('storage/'.$block->get('image')) }}" @endif></video>
                </div>
            @else
                <div class="card overflow-hidden rounded-(--radius-hero) p-2 shadow-lift sm:p-3" aria-hidden="true">
                    <div class="grid gap-3 rounded-[18px] bg-ivory p-4 sm:grid-cols-[180px_1fr] sm:p-6">
                        <div class="hidden flex-col gap-2 sm:flex">
                            @foreach (['Today', 'Bookings', 'Clients', 'AI Studio', 'Website'] as $i => $item)
                                <span @class(['rounded-lg px-3 py-2 text-start text-xs', 'bg-ink text-ivory' => $i === 0, 'text-muted' => $i !== 0])>{{ $item }}</span>
                            @endforeach
                        </div>
                        <div class="grid gap-3">
                            <div class="grid grid-cols-3 gap-3">
                                @foreach ([['QAR 48,200', 'Revenue today'], ['126', 'Bookings'], ['94%', 'Chair occupancy']] as [$v, $l])
                                    <div class="rounded-xl bg-white p-4 text-start shadow-soft"><p class="text-[10px] text-muted">{{ $l }}</p><p class="mt-1 font-display text-lg sm:text-2xl">{{ $v }}</p></div>
                                @endforeach
                            </div>
                            <div class="grid grid-cols-[1.4fr_1fr] gap-3">
                                <div class="rounded-xl bg-white p-4 shadow-soft">
                                    <div class="flex h-28 items-end gap-1.5 sm:h-36">
                                        @foreach ([38, 52, 44, 67, 58, 74, 63, 86, 71, 92, 80, 98] as $h)
                                            <span class="flex-1 rounded-t-md bg-gradient-to-t from-gold/70 to-gold/30" style="height: {{ $h }}%"></span>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="flex flex-col gap-2 rounded-xl bg-white p-4 text-start shadow-soft">
                                    @foreach ([['10:00', 'Balayage · Sofia'], ['11:30', 'Gel manicure · Mariam'], ['13:00', 'Bridal makeup · Amira']] as [$time, $what])
                                        <div class="flex items-center gap-2 text-[11px]"><span class="rounded bg-rose/60 px-1.5 py-0.5 font-medium">{{ $time }}</span><span class="truncate text-muted">{{ $what }}</span></div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>
