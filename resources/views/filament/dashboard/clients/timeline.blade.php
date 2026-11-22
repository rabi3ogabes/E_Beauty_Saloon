@php
    $tones = [
        'primary' => 'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300',
        'success' => 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300',
        'warning' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300',
        'info' => 'bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-300',
        'gray' => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300',
    ];
@endphp
@if ($events->isEmpty())
    <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-200 px-6 py-12 text-center dark:border-white/10">
        <x-filament::icon icon="heroicon-o-clock" class="h-8 w-8 text-gray-400" />
        <p class="mt-3 font-medium text-gray-900 dark:text-white">{{ __('workspace.timeline.empty') }}</p>
        <p class="mt-1 text-sm text-gray-500">{{ __('workspace.timeline.empty_body') }}</p>
    </div>
@else
    <ol class="relative space-y-6 border-s border-gray-200 ps-6 dark:border-white/10">
        @foreach ($events as $event)
            <li class="relative">
                <span class="absolute -start-[37px] flex h-6 w-6 items-center justify-center rounded-full ring-4 ring-white dark:ring-gray-900 {{ $tones[$event['color']] ?? $tones['gray'] }}">
                    <x-filament::icon :icon="$event['icon']" class="h-3.5 w-3.5" />
                </span>
                <div class="flex flex-wrap items-baseline justify-between gap-x-4">
                    <p class="font-medium text-gray-950 dark:text-white">{{ $event['title'] }}</p>
                    <time class="text-xs text-gray-500" datetime="{{ $event['at']->toIso8601String() }}">{{ $event['at']->translatedFormat('d M Y, H:i') }}</time>
                </div>
                @if ($event['body'])
                    <p class="mt-1 whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $event['body'] }}</p>
                @endif
                @if ($event['photo'])
                    <p class="mt-2 inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">
                        <x-filament::icon icon="heroicon-m-lock-closed" class="h-3 w-3" /> {{ __('workspace.timeline.private_photo') }}
                    </p>
                @endif
            </li>
        @endforeach
    </ol>
@endif
