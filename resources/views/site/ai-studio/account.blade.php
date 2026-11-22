<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-2xl font-semibold">{{ __('ai_studio.account.title') }}</h2>
        <a href="{{ route('studio.index') }}" class="btn-gold">{{ __('ai_studio.account.new_look') }}</a>
    </div>

    @if ($looks->isEmpty())
        <div class="card p-10 text-center">
            <p class="font-display text-xl">{{ __('ai_studio.account.empty_title') }}</p>
            <p class="mx-auto mt-2 max-w-sm text-sm text-muted">{{ __('ai_studio.account.empty_body') }}</p>
        </div>
    @else
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3">
            @foreach ($looks as $look)
                <a href="{{ route('studio.look', $look) }}" class="card-hover overflow-hidden">
                    <img src="{{ \App\Domain\AIStudio\Support\LookPresenter::mediaUrl($look, 'after') }}" alt="{{ $look->style?->name }}" loading="lazy" class="aspect-[3/4] w-full object-cover">
                    <div class="p-3">
                        <p class="text-sm font-medium">{{ $look->style?->name }}</p>
                        <p class="text-xs text-muted">{{ $look->created_at->translatedFormat('j M Y') }}</p>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    <div class="card flex flex-wrap items-center justify-between gap-3 p-5 text-sm">
        <p class="text-muted">{{ __('ai_studio.privacy.no_training') }}</p>
        <div class="flex gap-2">
            <a href="{{ route('studio.data.export') }}" class="btn-ghost">{{ __('ai_studio.privacy.export') }}</a>
            <form method="POST" action="{{ route('studio.data.erase') }}" onsubmit="return confirm(@js(__('ai_studio.privacy.erase_confirm')))">@csrf<button class="btn-ghost text-danger">{{ __('ai_studio.privacy.erase') }}</button></form>
        </div>
    </div>
</div>
