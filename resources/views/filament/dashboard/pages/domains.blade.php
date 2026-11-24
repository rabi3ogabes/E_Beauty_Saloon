<x-filament-panels::page>
    @php($readOnly = tenant()->isReadOnly())
    <div class="space-y-6">
        @foreach ($this->getDomains() as $domain)
            <section wire:key="domain-{{ $domain->id }}" class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ $domain->url() }}" target="_blank" rel="noopener" class="font-serif text-xl text-gray-950 hover:text-primary-600 dark:text-white">{{ $domain->domain }}</a>
                            @if ($domain->is_primary)
                                <x-filament::badge color="primary">{{ __('workspace.domains.primary') }}</x-filament::badge>
                            @endif
                            <x-filament::badge :color="$domain->isVerified() ? 'success' : 'warning'">
                                {{ $domain->isVerified() ? __('workspace.domains.status_verified') : __('workspace.domains.status_pending') }}
                            </x-filament::badge>
                            <x-filament::badge :color="$domain->ssl_status === 'active' ? 'success' : ($domain->ssl_status === 'failed' ? 'danger' : 'gray')">
                                SSL · {{ __('workspace.domains.ssl.'.$domain->ssl_status) }}
                            </x-filament::badge>
                        </div>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ $domain->type === 'subdomain' ? __('workspace.domains.included') : __('workspace.domains.custom') }}
                            @if ($domain->last_checked_at) · {{ __('workspace.domains.last_checked', ['time' => $domain->last_checked_at->diffForHumans()]) }} @endif
                        </p>
                        @if ($domain->last_error && ! $domain->isVerified())
                            <p class="mt-2 text-sm text-warning-700 dark:text-warning-400">{{ $domain->last_error }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($domain->type === 'custom')
                            <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-path" wire:click="checkNow({{ $domain->id }})" wire:loading.attr="disabled">{{ __('workspace.domains.check_now') }}</x-filament::button>
                        @endif
                        @if (! $readOnly && $domain->isVerified() && ! $domain->is_primary)
                            <x-filament::button size="sm" color="gray" wire:click="makePrimary({{ $domain->id }})">{{ __('workspace.domains.make_primary') }}</x-filament::button>
                        @endif
                        @if (! $readOnly && $domain->type === 'custom' && ! $domain->is_primary)
                            <x-filament::button size="sm" color="danger" outlined wire:click="removeDomain({{ $domain->id }})" wire:confirm="{{ __('workspace.domains.remove_confirm') }}">{{ __('salon.ui.delete') }}</x-filament::button>
                        @endif
                    </div>
                </div>

                @if ($domain->type === 'custom' && ! $domain->isVerified())
                    <div class="relative mt-5 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                        <table class="w-full min-w-[560px] text-sm">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wider text-gray-500 dark:bg-white/5">
                                <tr>
                                    <th class="px-4 py-2 text-start">{{ __('fields.type') }}</th>
                                    <th class="px-4 py-2 text-start">{{ __('workspace.domains.host') }}</th>
                                    <th class="px-4 py-2 text-start">{{ __('fields.value') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->instructions($domain) as $record)
                                    <tr class="border-t border-gray-100 dark:border-white/5">
                                        <td class="px-4 py-3"><x-filament::badge color="gray">{{ $record['type'] }}</x-filament::badge></td>
                                        @foreach (['host', 'value'] as $col)
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-2" x-data="{ copied: false }">
                                                    <code class="break-all rounded bg-gray-100 px-2 py-1 font-mono text-xs dark:bg-white/10">{{ $record[$col] }}</code>
                                                    <button type="button" class="text-gray-400 hover:text-primary-600" aria-label="{{ __('salon.ui.copy') }}"
                                                        x-on:click="navigator.clipboard.writeText(@js($record[$col])); copied = true; setTimeout(() => copied = false, 1500)">
                                                        <x-filament::icon icon="heroicon-o-clipboard" class="h-4 w-4" x-show="! copied" />
                                                        <x-filament::icon icon="heroicon-o-check" class="h-4 w-4 text-success-600" x-show="copied" x-cloak />
                                                    </button>
                                                </div>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-xs text-gray-500">{{ __('workspace.domains.dns_help') }}</p>
                @endif
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
