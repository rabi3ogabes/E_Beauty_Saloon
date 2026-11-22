<x-layouts.site :title="__('ai_studio.look.title')" :bare="$kiosk">
    <section class="container-luxe py-10 sm:py-16">
        <a href="{{ $kiosk ? route('studio.kiosk') : route('studio.index') }}" class="text-sm text-muted hover:text-ink">← {{ __('ai_studio.look.back') }}</a>

        @if (session('status'))<p class="mt-4 rounded-xl bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</p>@endif

        <div class="mt-6 grid gap-10 lg:grid-cols-[1.15fr_1fr]">
            {{-- Before / after slider --}}
            <div data-reveal>
                @if ($after)
                    <div x-data="{ pos: 50 }" dir="ltr" class="relative aspect-[3/4] select-none overflow-hidden rounded-(--radius-hero) bg-ink shadow-lift">
                        <img src="{{ $after }}" alt="{{ __('ai_studio.look.after') }}" class="absolute inset-0 h-full w-full object-cover" fetchpriority="high">
                        @if ($before)
                            <img src="{{ $before }}" alt="{{ __('ai_studio.look.before') }}" class="absolute inset-0 h-full w-full object-cover" :style="`clip-path: inset(0 ${100 - pos}% 0 0)`">
                            <div class="pointer-events-none absolute inset-y-0 w-0.5 bg-ivory/90" :style="`left: ${pos}%`"></div>
                            <span class="glass absolute top-4 left-4 rounded-full px-3 py-1 text-xs">{{ __('ai_studio.look.before') }}</span>
                            <span class="glass absolute top-4 right-4 rounded-full px-3 py-1 text-xs">{{ __('ai_studio.look.after') }}</span>
                            <input type="range" min="0" max="100" x-model="pos" dir="ltr" class="absolute inset-x-6 bottom-6 accent-[var(--brand-primary)]" aria-label="{{ __('ai_studio.look.compare') }}">
                        @endif
                    </div>
                @else
                    <div class="skeleton aspect-[3/4] rounded-(--radius-hero)"></div>
                @endif
                <p class="mt-3 text-xs text-muted">{{ __('ai_studio.look.disclaimer') }}</p>
            </div>

            {{-- Details --}}
            <div class="flex flex-col gap-6" data-reveal>
                <div>
                    <p class="eyebrow">{{ __('ai_studio.categories.'.($look->category ?? 'hair')) }}</p>
                    <h1 class="mt-2 text-4xl font-semibold">{{ $style?->name ?? __('ai_studio.look.title') }}</h1>
                    @if ($style?->description)<p class="mt-3 text-muted">{{ $style->description }}</p>@endif
                </div>

                @if ($service)
                    <div class="card divide-y hairline">
                        <div class="flex items-center justify-between p-5"><span class="text-sm text-muted">{{ __('ai_studio.look.service') }}</span><span class="font-medium">{{ $service->name }}</span></div>
                        <div class="flex items-center justify-between p-5"><span class="text-sm text-muted">{{ __('ai_studio.look.price') }}</span><span class="font-display text-xl">{{ money($price) }}</span></div>
                        <div class="flex items-center justify-between p-5"><span class="text-sm text-muted">{{ __('ai_studio.look.duration') }}</span><span>{{ $duration }} {{ __('salon.ui.minutes') }}</span></div>
                        @if ($stylist)
                            <div class="flex items-center justify-between p-5"><span class="text-sm text-muted">{{ __('ai_studio.look.stylist') }}</span><span>{{ $stylist->name }} <span class="text-xs text-muted">· {{ $stylist->title }}</span></span></div>
                        @endif
                    </div>
                @endif

                <div class="flex flex-wrap gap-3">
                    @if ($bookUrl)<a href="{{ $bookUrl }}" class="btn-primary" data-book-look>{{ __('ai_studio.look.book') }}</a>@endif
                    @foreach ($shades as $shade)
                        @if ($shadeUrl = \App\Domain\AIStudio\Support\LookPresenter::shadeUrl($shade))
                            <a href="{{ $shadeUrl }}" class="btn-ghost">
                                @if ($shade->swatch)<span class="h-4 w-4 rounded-full" style="background: {{ e($shade->swatch) }}"></span>@endif
                                {{ __('ai_studio.look.buy_shade', ['shade' => $shade->displayName()]) }}
                            </a>
                        @endif
                    @endforeach
                </div>

                @unless ($kiosk)
                    <div class="card p-5">
                        <p class="text-sm font-medium">{{ __('ai_studio.look.keep_title') }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($look->saved)
                                <span class="chip chip-active">✓ {{ __('ai_studio.look.saved_badge') }}</span>
                            @elseif (auth('client')->check())
                                <form method="POST" action="{{ route('studio.look.save', $look) }}">@csrf<button class="btn-gold">{{ __('ai_studio.look.save') }}</button></form>
                            @else
                                <a href="{{ route('client.login') }}" class="btn-gold">{{ __('ai_studio.look.login_to_save') }}</a>
                            @endif
                            @if ($shareUrl)
                                <a href="https://wa.me/?text={{ rawurlencode(__('ai_studio.look.share_text', ['salon' => $tenant->name]).' '.$shareUrl) }}" target="_blank" rel="noopener" class="btn-ghost">WhatsApp</a>
                                <a href="{{ $shareUrl }}" download class="btn-ghost" x-data @click="if (navigator.share) { $event.preventDefault(); fetch(@js($shareUrl)).then(r => r.blob()).then(b => navigator.share({ files: [new File([b], 'look.jpg', { type: 'image/jpeg' })], title: @js($tenant->name) })).catch(() => window.open(@js($shareUrl))) }">Instagram</a>
                            @endif
                        </div>
                        @unless ($look->saved)
                            <p class="mt-3 text-xs text-muted">{{ __('ai_studio.look.expires', ['date' => $look->expires_at?->translatedFormat('j M')]) }}</p>
                        @endunless
                    </div>
                @else
                    <a href="{{ route('studio.kiosk', ['reset' => 1]) }}" class="btn-ghost">{{ __('ai_studio.look.start_over') }}</a>
                @endunless
            </div>
        </div>
    </section>
</x-layouts.site>
