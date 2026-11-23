<x-filament-panels::page>
    @if ($completed)
        @php($url = $this->getSalonUrl())
        <div class="mx-auto flex max-w-2xl flex-col items-center py-10 text-center">
            <div class="flex h-20 w-20 items-center justify-center rounded-full border border-primary-200 bg-primary-50 dark:border-primary-500/30 dark:bg-primary-500/10">
                <x-filament::icon icon="heroicon-o-check" class="h-9 w-9 text-primary-600" />
            </div>
            <p class="mt-8 text-xs font-medium uppercase tracking-[0.3em] text-primary-700 dark:text-primary-400">{{ __('workspace.onboarding.done_eyebrow') }}</p>
            <h1 class="mt-3 font-serif text-4xl leading-tight text-gray-950 dark:text-white">{{ __('workspace.onboarding.done_title') }}</h1>
            <p class="mt-4 text-lg text-gray-600 dark:text-gray-300">{!! __('workspace.onboarding.done_body', ['url' => '<a href="'.e($url).'" target="_blank" rel="noopener" class="font-medium text-primary-700 underline underline-offset-4 dark:text-primary-400">'.e(preg_replace('#^https?://#', '', rtrim($url, '/'))).'</a>']) !!}</p>
            <div class="mt-10 grid w-full gap-3 sm:grid-cols-3">
                @foreach ([
                    ['icon' => 'heroicon-o-user-group', 'label' => __('workspace.onboarding.next_team'), 'url' => \App\Filament\Dashboard\Resources\Staff\StaffResource::getUrl('create')],
                    ['icon' => 'heroicon-o-sparkles', 'label' => __('workspace.onboarding.next_services'), 'url' => \App\Filament\Dashboard\Resources\Services\ServiceResource::getUrl('index')],
                    ['icon' => 'heroicon-o-globe-alt', 'label' => __('workspace.onboarding.next_website'), 'url' => $url],
                ] as $next)
                    <a href="{{ $next['url'] }}" class="group rounded-2xl border border-gray-200 bg-white p-5 text-start transition duration-200 ease-out hover:-translate-y-0.5 hover:shadow-md dark:border-white/10 dark:bg-gray-900">
                        <x-filament::icon :icon="$next['icon']" class="h-5 w-5 text-primary-600" />
                        <p class="mt-3 text-sm font-medium text-gray-950 group-hover:text-primary-700 dark:text-white">{{ $next['label'] }}</p>
                    </a>
                @endforeach
            </div>
            <x-filament::button tag="a" :href="\App\Filament\Dashboard\Pages\Dashboard::getUrl()" class="mt-10" size="lg">{{ __('workspace.onboarding.go_dashboard') }}</x-filament::button>
        </div>
    @else
        <form wire:submit="complete">
            {{ $this->form }}
        </form>
    @endif
</x-filament-panels::page>
