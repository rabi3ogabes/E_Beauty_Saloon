{{-- Gift card visual. Props: $design (champagne|noir|rose), $amount (string), $to, $from?, $code?, $salon. Works with Alpine bindings via $alpine = true. --}}
@php
    $alpine = $alpine ?? false;
    $styles = [
        'champagne' => 'background: radial-gradient(120% 140% at 0% 0%, #F3E7CF 0%, #D9BF8A 45%, #B8975A 100%); color: #2A2216;',
        'noir' => 'background: radial-gradient(120% 140% at 100% 0%, #3A2F3D 0%, #1E1620 55%, #0E0A0F 100%); color: #F4E9D3;',
        'rose' => 'background: radial-gradient(120% 140% at 0% 100%, #FBEDE8 0%, #E9D6CF 50%, #C9A39A 100%); color: #3B2622;',
    ];
@endphp
<div class="relative aspect-[1.586/1] w-full overflow-hidden rounded-[22px] shadow-lift ring-1 ring-black/5"
     @if ($alpine) :style="@js($styles)[design]" @else style="{{ $styles[$design] ?? $styles['champagne'] }}" @endif>
    <div class="pointer-events-none absolute -end-16 -top-16 h-56 w-56 rounded-full opacity-30" style="background: radial-gradient(circle, rgba(255,255,255,.65), transparent 65%)"></div>
    <div class="pointer-events-none absolute inset-3 rounded-[16px] border border-current opacity-20"></div>
    <div class="relative flex h-full flex-col justify-between p-6 sm:p-8">
        <div class="flex items-start justify-between gap-4">
            <span class="font-display text-lg tracking-wide sm:text-xl">{{ $salon }}</span>
            <span class="text-[11px] uppercase tracking-[0.3em] opacity-70">{{ __('marketing.gift_cards.card_label') }}</span>
        </div>
        <div>
            <p class="font-display text-4xl font-semibold sm:text-5xl" @if ($alpine) x-text="amountLabel" @endif>{{ $amount }}</p>
            <div class="mt-3 flex flex-wrap items-end justify-between gap-2 text-sm">
                <p class="opacity-80">
                    {{ __('marketing.gift_cards.for') }} <span class="font-medium" @if ($alpine) x-text="to || '—'" @endif>{{ $to ?: '—' }}</span>
                    @if (! empty($from) || $alpine)
                        <span class="opacity-70"> · {{ __('marketing.gift_cards.from') }} <span @if ($alpine) x-text="from || '—'" @endif>{{ $from ?? '' }}</span></span>
                    @endif
                </p>
                @if (! empty($code))<p class="font-mono text-xs tracking-[0.2em] opacity-80" dir="ltr">{{ $code }}</p>@endif
            </div>
        </div>
    </div>
</div>
