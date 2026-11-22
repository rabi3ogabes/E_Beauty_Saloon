<div class="rounded-2xl border border-gray-200 bg-gray-50/60 p-5 dark:border-white/10 dark:bg-white/5">
    <p class="text-xs font-medium uppercase tracking-[0.2em] text-gray-500">{{ __('workspace.branches.upgrade_due_now') }}</p>
    <p class="mt-1 font-serif text-3xl text-gray-950 dark:text-white">{{ $prorated }}</p>
    <p class="mt-1 text-sm text-gray-500">{{ __('workspace.branches.upgrade_prorated', ['days' => $days]) }}</p>
    <div class="mt-4 flex items-center justify-between border-t border-gray-200 pt-3 text-sm dark:border-white/10">
        <span class="text-gray-600 dark:text-gray-300">{{ __('workspace.branches.upgrade_new_total', ['branches' => $branches]) }}</span>
        <span class="font-semibold text-gray-950 dark:text-white">{{ $renewal }} <span class="font-normal text-gray-500">/ {{ trans_choice('salon.billing.cycle', $cycle, ['count' => $cycle]) }}</span></span>
    </div>
</div>
