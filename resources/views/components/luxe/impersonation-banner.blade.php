@if ($imp = session('impersonation'))
    <div class="luxe-impersonation-banner sticky top-0 z-50 flex items-center justify-center gap-3 px-4 py-2 text-sm font-medium" role="status">
        <x-filament::icon icon="heroicon-m-eye" class="h-4 w-4" />
        <span>{{ __('salon.impersonation.banner', ['owner' => auth()->user()?->name, 'admin' => $imp['admin_name'] ?? 'Support']) }}</span>
        <a href="{{ route('impersonate.exit') }}" class="rounded-full bg-black/85 px-3 py-1 text-xs text-white transition hover:bg-black">{{ __('salon.impersonation.exit') }}</a>
    </div>
@endif
