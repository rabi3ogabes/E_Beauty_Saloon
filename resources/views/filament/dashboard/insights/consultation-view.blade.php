@php($answers = $form->answers ?? [])
<div class="space-y-4 text-sm">
    @if ($form->isSigned())
        <dl class="grid gap-3 sm:grid-cols-2">
            @foreach (\App\Domain\Insights\Models\ConsultationForm::QUESTIONS as $q)
                <div class="rounded-xl bg-gray-50 px-3 py-2 dark:bg-white/5">
                    <dt class="text-xs uppercase tracking-wide text-gray-500">{{ __('insights.consultation.q.'.$q) }}</dt>
                    <dd class="mt-1 text-gray-900 dark:text-white">
                        @if ($q === 'pregnant')
                            {{ ($answers[$q] ?? false) ? __('salon.ui.yes') : __('salon.ui.no') }}
                        @elseif ($q === 'patch_test')
                            {{ isset($answers[$q]) ? __('insights.consultation.patch.'.$answers[$q]) : '—' }}
                        @else
                            {{ ($answers[$q] ?? null) ?: '—' }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('insights.consultation.signature') }} · {{ $form->signed_name }} · {{ $form->signed_at?->setTimezone(tenant()->timezone)->format('j M Y H:i') }}</p>
            <img src="{{ route('tenant.insights.consultation-signature', $form) }}" alt="{{ __('insights.consultation.signature') }}" class="mt-2 h-32 rounded-xl border border-gray-100 bg-white p-2 dark:border-white/10">
        </div>
    @else
        <p class="text-gray-500">{{ __('insights.consultation.waiting') }}</p>
        <p class="break-all text-xs text-gray-400" dir="ltr">{{ rtrim(tenant()->url(), '/').$form->publicPath() }}</p>
    @endif
    @if ($form->client && \App\Filament\Dashboard\Resources\Clients\ClientResource::canViewAny())
        <x-filament::link :href="\App\Filament\Dashboard\Resources\Clients\ClientResource::getUrl('view', ['record' => $form->client])" icon="heroicon-m-photo">{{ __('insights.consultation.photo_vault') }}</x-filament::link>
    @endif
</div>
