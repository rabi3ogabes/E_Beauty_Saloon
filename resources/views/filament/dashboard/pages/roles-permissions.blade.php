<x-filament-panels::page>
    @php($matrix = $this->getMatrix())
    @php($roles = array_keys($matrix))
    @php($editable = $this->canEdit())

    <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
        <table class="w-full min-w-[760px] text-sm">
            <thead class="sticky top-0 z-10 bg-white/95 backdrop-blur dark:bg-gray-900/95">
                <tr class="border-b border-gray-200 dark:border-white/10">
                    <th class="px-5 py-4 text-start text-xs font-medium uppercase tracking-[0.18em] text-gray-500">{{ __('workspace.roles.permission') }}</th>
                    @foreach ($roles as $role)
                        <th class="px-3 py-4 text-center">
                            <span class="block font-semibold text-gray-950 dark:text-white">{{ __('workspace.roles.names.'.$role) }}</span>
                            @if ($role === 'owner')
                                <span class="mt-1 inline-flex items-center gap-1 rounded-full bg-primary-50 px-2 py-0.5 text-[11px] text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">
                                    <x-filament::icon icon="heroicon-m-lock-closed" class="h-3 w-3" /> {{ __('workspace.roles.all_access') }}
                                </span>
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($this->getAreas() as $area => $abilities)
                    <tr class="bg-gray-50/70 dark:bg-white/5">
                        <td colspan="{{ count($roles) + 1 }}" class="px-5 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">{{ __('workspace.permission_areas.'.$area) }}</td>
                    </tr>
                    @foreach ($abilities as $ability)
                        @php($perm = $area.'.'.$ability)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5" wire:key="row-{{ $perm }}">
                            <td class="px-5 py-3">
                                <span class="text-gray-900 dark:text-gray-100">{{ __('workspace.permissions.'.$area.'_'.$ability) }}</span>
                                <span class="block font-mono text-[11px] text-gray-400">{{ $perm }}</span>
                            </td>
                            @foreach ($roles as $role)
                                @php($on = $role === 'owner' || in_array($perm, $matrix[$role], true))
                                <td class="px-3 py-3 text-center">
                                    <button type="button"
                                        role="switch" aria-checked="{{ $on ? 'true' : 'false' }}"
                                        aria-label="{{ __('workspace.roles.names.'.$role) }} — {{ $perm }}"
                                        @if ($role !== 'owner' && $editable) wire:click="toggle('{{ $role }}', '{{ $perm }}')" @else disabled @endif
                                        @class([
                                            'relative inline-flex h-6 w-11 items-center rounded-full transition duration-200 ease-out focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
                                            'bg-primary-500' => $on,
                                            'bg-gray-200 dark:bg-white/10' => ! $on,
                                            'cursor-not-allowed opacity-60' => $role === 'owner' || ! $editable,
                                        ])>
                                        <span @class(['inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200 ease-out', 'translate-x-5 rtl:-translate-x-5' => $on, 'translate-x-0.5 rtl:-translate-x-0.5' => ! $on])></span>
                                    </button>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="text-xs text-gray-500">{{ __('workspace.roles.footnote') }}</p>
</x-filament-panels::page>
