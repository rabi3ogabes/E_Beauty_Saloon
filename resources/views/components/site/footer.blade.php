@php($t = tenant())
<footer class="mt-24 border-t hairline bg-white/50 dark:bg-plum/40">
    <div class="container-luxe grid gap-10 py-14 md:grid-cols-4">
        <div class="md:col-span-2">
            <p class="font-display text-2xl">{{ $t?->name }}</p>
            @if ($t?->brand('about'))
                <p class="mt-3 max-w-md text-sm text-muted">{{ \Illuminate\Support\Str::limit(strip_tags($t->brand('about')), 180) }}</p>
            @endif
            <div class="mt-5 flex gap-2">
                @foreach (($t?->brand('social') ?? []) as $network => $url)
                    @if ($url)
                        <a href="{{ $url }}" class="chip min-h-10 px-3 text-xs capitalize" rel="noopener" target="_blank">{{ $network }}</a>
                    @endif
                @endforeach
            </div>
        </div>
        <div>
            <p class="eyebrow">{{ __('site.footer.visit') }}</p>
            <ul class="mt-4 space-y-3 text-sm">
                @foreach (\App\Domain\Branches\Models\Branch::active()->orderBy('sort')->limit(4)->get() as $branch)
                    <li><a href="{{ url('/branches/'.$branch->slug) }}" class="hover:text-gold-dark">{{ $branch->name }}</a>
                        @if ($branch->area)<span class="block text-muted">{{ $branch->area }}</span>@endif
                    </li>
                @endforeach
            </ul>
        </div>
        <div>
            <p class="eyebrow">{{ __('site.footer.explore') }}</p>
            <ul class="mt-4 space-y-3 text-sm">
                @if ($t?->hasModule('booking'))<li><a href="{{ url('/book') }}" class="hover:text-gold-dark">{{ __('site.nav.book') }}</a></li>@endif
                @if ($t?->hasModule('shop'))<li><a href="{{ url('/shop') }}" class="hover:text-gold-dark">{{ __('site.nav.shop') }}</a></li>@endif
                @if ($t?->hasModule('ai_studio'))<li><a href="{{ url('/studio') }}" class="hover:text-gold-dark">{{ __('site.nav.studio') }}</a></li>@endif
                <li><a href="{{ url('/gift-cards') }}" class="hover:text-gold-dark">{{ __('site.nav.gift_cards') }}</a></li>
                <li><a href="{{ url('/account') }}" class="hover:text-gold-dark">{{ __('site.nav.account') }}</a></li>
            </ul>
        </div>
    </div>
    <div class="border-t hairline">
        <div class="container-luxe flex flex-col items-center justify-between gap-2 py-6 text-xs text-muted sm:flex-row">
            <p>© {{ now()->year }} {{ $t?->name }}. {{ __('site.footer.rights') }}</p>
            <p>{{ __('site.footer.powered', ['platform' => config('salon.name')]) }}</p>
        </div>
    </div>
</footer>
