<x-filament-panels::page>
    @php($sessions = $this->getImpersonations())
    <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="flex items-center gap-2">
            <x-filament::icon icon="heroicon-o-lifebuoy" class="h-5 w-5 text-primary-600" />
            <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('workspace.security.support_access') }}</h2>
        </div>
        <p class="mt-1 text-sm text-gray-500">{{ __('workspace.security.support_help') }}</p>
        @if ($sessions->isEmpty())
            <p class="mt-4 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ __('workspace.security.no_support') }}</p>
        @else
            <ul class="mt-4 divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($sessions as $s)
                    <li class="flex flex-wrap items-start justify-between gap-2 py-3 text-sm">
                        <span class="text-gray-900 dark:text-gray-100">
                            {{ __('workspace.security.support_line', ['date' => $s->started_at->translatedFormat('d M Y, H:i'), 'reason' => $s->reason]) }}
                        </span>
                        <span class="text-xs text-gray-500">
                            {{ $s->ended_at ? __('workspace.security.duration', ['minutes' => max(1, (int) $s->started_at->diffInMinutes($s->ended_at))]) : __('workspace.security.ongoing') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{ $this->table }}
</x-filament-panels::page>
