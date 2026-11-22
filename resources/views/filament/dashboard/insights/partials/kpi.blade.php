<div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/5">
    <p class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</p>
    <p class="mt-2 font-display text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</p>
    @isset($hint)
        <p @class(['mt-1 text-xs', 'text-success-600' => ($tone ?? null) === 'up', 'text-danger-600' => ($tone ?? null) === 'down', 'text-gray-500' => ! isset($tone)])>{{ $hint }}</p>
    @endisset
</div>
