@php
    $on = \App\Domain\Workspace\Branding\Color::onColor($b['primary']);
    $fonts = collect([$b['font_display'], $b['font_body']])->unique()->map(fn ($f) => 'family='.str_replace(' ', '+', $f).':wght@400;600')->join('&');
    $display = "'".$b['font_display']."', serif";
    $body = "'".$b['font_body']."', sans-serif";
    $name = $tenant?->name ?? 'Maison';
@endphp
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?{{ $fonts }}&display=swap">
<div class="space-y-4" aria-live="polite">
    <p class="text-xs font-medium uppercase tracking-[0.2em] text-gray-500">{{ __('workspace.branding.live_preview') }}</p>

    {{-- Website hero card --}}
    <div class="overflow-hidden rounded-3xl border border-gray-200 shadow-sm dark:border-white/10" style="background: {{ $b['background'] }}; color: {{ $b['ink'] }}; font-family: {{ $body }}">
        <div class="relative h-40" style="background: {{ $b['hero'] ? 'url('.e($b['hero']).') center/cover' : 'linear-gradient(135deg, '.$b['accent'].', '.$b['primary'].')' }}">
            <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent"></div>
            <div class="absolute start-4 top-4 flex items-center gap-2">
                @if ($b['logo'])
                    <img src="{{ $b['logo'] }}" alt="" class="h-8 w-auto max-w-[120px] object-contain">
                @else
                    <span class="text-sm font-semibold text-white" style="font-family: {{ $display }}">{{ $name }}</span>
                @endif
            </div>
            <p class="absolute bottom-3 start-4 text-[10px] uppercase tracking-[0.3em] text-white/80">{{ __('workspace.branding.preview_website') }}</p>
        </div>
        <div class="p-5">
            <h3 class="text-2xl leading-tight" style="font-family: {{ $display }}">{{ __('workspace.branding.preview_headline') }}</h3>
            <p class="mt-2 line-clamp-2 text-sm opacity-70">{{ $b['about'] ?: __('workspace.branding.preview_about') }}</p>
            <div class="mt-4 flex items-center gap-2">
                <span class="inline-flex min-h-[44px] items-center px-5 text-sm font-medium shadow-sm" style="background: {{ $b['primary'] }}; color: {{ $on }}; border-radius: {{ $b['radius'] }}">{{ __('workspace.branding.book_now') }}</span>
                <span class="inline-flex min-h-[44px] items-center border px-5 text-sm" style="border-color: {{ $b['primary'] }}; color: {{ $b['ink'] }}; border-radius: {{ $b['radius'] }}">{{ __('workspace.branding.view_services') }}</span>
            </div>
        </div>
    </div>

    {{-- Booking step --}}
    <div class="rounded-2xl border border-gray-200 p-4 dark:border-white/10" style="background: {{ $b['background'] }}; color: {{ $b['ink'] }}; font-family: {{ $body }}">
        <p class="text-[10px] uppercase tracking-[0.3em] opacity-60">{{ __('workspace.branding.preview_booking') }}</p>
        <div class="mt-3 grid grid-cols-4 gap-2 text-center text-xs">
            @foreach (['10:00', '10:30', '11:00', '11:30'] as $i => $slot)
                <span class="rounded-xl border py-2" style="{{ $i === 1 ? 'background: '.$b['primary'].'; color: '.$on.'; border-color: '.$b['primary'] : 'border-color: '.$b['accent'] }}">{{ $slot }}</span>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        {{-- 80mm receipt --}}
        <div class="rounded-xl border border-gray-200 bg-white p-3 font-mono text-[10px] leading-relaxed text-gray-800 shadow-sm dark:border-white/10" style="max-width: 80mm">
            <div class="text-center">
                @if ($b['logo'])
                    <img src="{{ $b['logo'] }}" alt="" class="mx-auto h-6 w-auto object-contain grayscale">
                @else
                    <p class="text-xs font-semibold" style="font-family: {{ $display }}">{{ $name }}</p>
                @endif
                <p class="mt-1 text-gray-500">{{ now()->format('d/m/Y H:i') }}</p>
            </div>
            <div class="my-2 border-t border-dashed border-gray-300"></div>
            <div class="flex justify-between"><span>{{ __('workspace.branding.preview_item') }}</span><span>180.00</span></div>
            <div class="flex justify-between"><span>{{ __('workspace.branding.preview_addon') }}</span><span>45.00</span></div>
            <div class="my-2 border-t border-dashed border-gray-300"></div>
            <div class="flex justify-between font-semibold"><span>{{ __('salon.billing.total') }}</span><span>{{ $tenant?->currency ?? 'QAR' }} 225.00</span></div>
            <p class="mt-2 whitespace-pre-line text-center text-gray-500">{{ $b['receipt_footer'] ?: __('workspace.branding.receipt_placeholder') }}</p>
        </div>

        {{-- Push notification --}}
        <div class="self-start rounded-2xl bg-gray-900/90 p-3 text-white shadow-lg backdrop-blur">
            <div class="flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center overflow-hidden rounded-md" style="background: {{ $b['primary'] }}">
                    @if ($b['logo'])<img src="{{ $b['logo'] }}" alt="" class="h-4 w-4 object-contain">@else<span class="text-[10px]" style="color: {{ $on }}">{{ mb_substr($name, 0, 1) }}</span>@endif
                </span>
                <span class="text-[11px] font-semibold">{{ $name }}</span>
                <span class="ms-auto text-[10px] text-white/50">{{ __('workspace.branding.now') }}</span>
            </div>
            <p class="mt-2 text-[11px] font-medium">{{ __('workspace.branding.push_title') }}</p>
            <p class="text-[11px] text-white/70">{{ __('workspace.branding.push_body') }}</p>
        </div>
    </div>
</div>
