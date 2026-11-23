@php($current = app()->getLocale())
<div class="flex items-center gap-1 rounded-full border border-gray-200 p-0.5 text-xs dark:border-white/10" role="group" aria-label="{{ __('salon.ui.language') }}">
    @foreach (config('salon.locales') as $code => $label)
        <a href="{{ route('locale.switch', $code) }}"
           @class([
               'rounded-full px-2.5 py-1 font-medium transition',
               'bg-gray-900 text-white dark:bg-primary-400 dark:text-gray-950' => $current === $code,
               'text-gray-500 hover:text-gray-900 dark:hover:text-white' => $current !== $code,
           ])
           @if ($current === $code) aria-current="true" @endif
           lang="{{ $code }}">{{ $code === 'ar' ? 'ع' : 'EN' }}</a>
    @endforeach
</div>
