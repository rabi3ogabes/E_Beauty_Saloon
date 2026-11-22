<div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('admin.domain.dns_help') }}</p>
    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase tracking-wider text-gray-500 dark:bg-white/5">
                <tr><th class="px-4 py-2 text-start">{{ __('fields.type') }}</th><th class="px-4 py-2 text-start">Host</th><th class="px-4 py-2 text-start">{{ __('fields.value') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($records as $r)
                    <tr>
                        <td class="px-4 py-3 font-semibold">{{ $r['type'] }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $r['host'] }}</td>
                        <td class="px-4 py-3 font-mono text-xs break-all" x-data="{ c: false }">
                            {{ $r['value'] }}
                            <button type="button" class="ms-2 text-primary-600" x-on:click="navigator.clipboard.writeText(@js($r['value'])); c = true; setTimeout(() => c = false, 1500)">
                                <span x-show="!c">{{ __('salon.ui.copy') }}</span><span x-show="c" x-cloak>{{ __('salon.ui.copied') }}</span>
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
