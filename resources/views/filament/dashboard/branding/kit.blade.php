@php($kit = $this->kit)
@if ($kit)
    <div class="rounded-2xl border border-gray-200 p-4 dark:border-white/10">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ __('workspace.branding.kit_title') }}</p>
            <div class="flex items-center gap-1.5" aria-label="{{ __('workspace.branding.extracted') }}">
                @foreach ($kit['palette'] as $hex)
                    <span class="h-6 w-6 rounded-full border border-black/5 shadow-sm" style="background: {{ $hex }}" title="{{ $hex }}"></span>
                @endforeach
            </div>
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            @foreach ($kit['presets'] as $i => $preset)
                <button type="button" wire:click="applyPreset({{ $i }})"
                    class="group overflow-hidden rounded-2xl border border-gray-200 text-start transition duration-200 ease-out hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:border-white/10">
                    <div class="p-4" style="background: {{ $preset['background'] }}; color: {{ $preset['ink'] }}">
                        <p class="text-[10px] uppercase tracking-[0.25em] opacity-60">{{ $preset['name'] }}</p>
                        <p class="mt-1 text-lg" style="font-family: '{{ $preset['font_display'] }}', serif">Aa — Maison</p>
                        <span class="mt-3 inline-block px-3 py-1 text-xs" style="background: {{ $preset['primary'] }}; color: {{ \App\Domain\Workspace\Branding\Color::onColor($preset['primary']) }}; border-radius: {{ $preset['button_style'] === 'pill' ? '999px' : '10px' }}">{{ __('workspace.branding.book_now') }}</span>
                    </div>
                    <div class="flex h-2">
                        <span class="flex-1" style="background: {{ $preset['primary'] }}"></span>
                        <span class="flex-1" style="background: {{ $preset['accent'] }}"></span>
                        <span class="flex-1" style="background: {{ $preset['ink'] }}"></span>
                    </div>
                    <p class="px-4 py-2 text-xs text-gray-500 group-hover:text-primary-600">{{ $preset['font_display'] }} · {{ $preset['font_body'] }} — {{ __('workspace.branding.apply') }}</p>
                </button>
            @endforeach
        </div>
    </div>
@endif
