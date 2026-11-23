@php
    $looks = [
        ['label' => __('platform.studio.looks.honey'), 'from' => '#5a3a22', 'to' => '#d9ad6c'],
        ['label' => __('platform.studio.looks.copper'), 'from' => '#4a2216', 'to' => '#c0602f'],
        ['label' => __('platform.studio.looks.espresso'), 'from' => '#1e120c', 'to' => '#4b2e1f'],
        ['label' => __('platform.studio.looks.rose'), 'from' => '#6b3a43', 'to' => '#e2a5ae'],
    ];
@endphp
<section id="studio" class="site-section">
    <div class="container-luxe">
        <div class="relative overflow-hidden rounded-(--radius-hero) bg-[#1E1620] px-6 py-14 text-[#F5EFE6] sm:px-14 sm:py-20">
            <div class="pointer-events-none absolute -end-20 -top-24 h-96 w-96 rounded-full bg-[#C8A96A] opacity-30 blur-3xl" aria-hidden="true"></div>
            <div class="relative grid items-center gap-12 lg:grid-cols-2">
                <div data-reveal>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-[#C8A96A]">{{ $block->t('eyebrow') }}</p>
                    <h2 class="mt-4 text-3xl font-semibold sm:text-5xl">{{ $block->t('title') }}</h2>
                    <p class="mt-5 max-w-lg text-[#F5EFE6]/70">{{ $block->t('body') }}</p>
                    <ul class="mt-8 grid gap-3 text-sm text-[#F5EFE6]/80 sm:grid-cols-2">
                        @foreach (__('platform.studio.bullets') as $bullet)<li class="flex gap-2"><span class="text-[#C8A96A]">✦</span>{{ $bullet }}</li>@endforeach
                    </ul>
                    <a href="{{ url('/features/ai_studio') }}" class="btn-gold mt-10 px-8">{{ __('platform.studio.cta') }}</a>
                </div>
                {{-- Interactive demo: pick a look, slide before / after (CSS only + Alpine). --}}
                <div x-data="{ look: 0, pos: 55, looks: @js($looks) }" class="mx-auto w-full max-w-md" data-reveal>
                    <div dir="ltr" class="relative aspect-[4/5] overflow-hidden rounded-[28px] border border-white/10 bg-[#2a2028] shadow-2xl">
                        <svg viewBox="0 0 400 500" class="absolute inset-0 h-full w-full" aria-hidden="true">
                            <defs>
                                <linearGradient id="hair-after" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" :stop-color="looks[look].from"/><stop offset="100%" :stop-color="looks[look].to"/></linearGradient>
                                <linearGradient id="hair-before" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#2b1d16"/><stop offset="100%" stop-color="#3a281e"/></linearGradient>
                                <clipPath id="before-clip"><rect x="0" y="0" :width="pos * 4" height="500"/></clipPath>
                            </defs>
                            <rect width="400" height="500" fill="#3b2c35"/>
                            <path d="M90 470 C 70 300, 110 120, 200 110 C 290 120, 330 300, 310 470 Z" fill="url(#hair-after)"/>
                            <g clip-path="url(#before-clip)"><path d="M90 470 C 70 300, 110 120, 200 110 C 290 120, 330 300, 310 470 Z" fill="url(#hair-before)"/></g>
                            <ellipse cx="200" cy="245" rx="78" ry="98" fill="#e7c3a6"/>
                            <path d="M122 220 C 130 150, 270 150, 278 220 C 250 175, 150 175, 122 220 Z" fill="url(#hair-after)"/>
                            <g clip-path="url(#before-clip)"><path d="M122 220 C 130 150, 270 150, 278 220 C 250 175, 150 175, 122 220 Z" fill="url(#hair-before)"/></g>
                            <rect x="150" y="335" width="100" height="90" rx="40" fill="#e7c3a6"/>
                            <path d="M90 500 C 110 420, 290 420, 310 500 Z" fill="#C8A96A" opacity=".85"/>
                        </svg>
                        <div class="pointer-events-none absolute inset-y-0 w-px bg-white/80" :style="`left: ${pos}%`"></div>
                        <span class="absolute top-4 left-4 rounded-full bg-black/40 px-3 py-1 text-xs backdrop-blur">{{ __('ai_studio.look.before') }}</span>
                        <span class="absolute top-4 right-4 rounded-full bg-black/40 px-3 py-1 text-xs backdrop-blur">{{ __('ai_studio.look.after') }}</span>
                        <input type="range" min="0" max="100" x-model="pos" class="absolute inset-x-6 bottom-5 accent-[#C8A96A]" aria-label="{{ __('ai_studio.look.compare') }}">
                    </div>
                    <div class="mt-5 flex flex-wrap justify-center gap-2">
                        <template x-for="(l, i) in looks" :key="i">
                            <button type="button" @click="look = i" class="rounded-full border px-4 py-2 text-xs transition" :class="look === i ? 'border-[#C8A96A] bg-[#C8A96A] text-[#1E1620]' : 'border-white/20 text-[#F5EFE6]/80 hover:border-white/50'" x-text="l.label"></button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
