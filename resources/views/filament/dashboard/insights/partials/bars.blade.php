{{-- Minimal bar chart. $series: [label => value]; $format: closure for tooltips; $color --}}
@php
    $values = array_values($series);
    $max = max(1, ...($values ?: [1]));
    $count = max(1, count($values));
@endphp
<div class="flex h-40 items-end gap-[3px]" dir="ltr" role="img" aria-label="{{ $label ?? '' }}">
    @foreach ($series as $key => $value)
        <div class="group relative flex h-full flex-1 items-end" wire:key="bar-{{ $key }}">
            <div class="w-full rounded-t-[4px] transition-all duration-200 ease-out group-hover:opacity-80"
                 style="height: {{ max(2, round($value / $max * 100, 1)) }}%; background: {{ $value > 0 ? ($color ?? '#C8A96A') : '#E9D6CF' }}"></div>
            <div class="pointer-events-none absolute -top-8 left-1/2 z-10 hidden -translate-x-1/2 whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-[11px] text-white group-hover:block">
                {{ $key }} · {{ isset($format) ? $format($value) : $value }}
            </div>
        </div>
    @endforeach
</div>
@if ($count > 1)
    <div class="mt-1 flex justify-between text-[11px] text-gray-400" dir="ltr">
        <span>{{ array_key_first($series) }}</span><span>{{ array_key_last($series) }}</span>
    </div>
@endif
