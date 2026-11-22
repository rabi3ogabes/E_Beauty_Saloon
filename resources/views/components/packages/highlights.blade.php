@props(['limit' => 3])
{{-- Featured packages for the salon website "packages" section (Marketing module data). --}}
@php
    $packages = \App\Domain\Marketing\Models\Package::query()->online()->with('items.itemable')->orderBy('id')->limit($limit)->get();
@endphp
@if ($packages->isNotEmpty())
    <div class="grid gap-6 md:grid-cols-3">
        @foreach ($packages as $package)
            <a href="{{ url('/packages') }}#package-{{ $package->id }}" class="card-hover flex flex-col p-6 sm:p-7" data-reveal>
                <p class="eyebrow">{{ trans_choice('marketing.packages.sessions', $package->sessions_count, ['count' => $package->sessions_count]) }}</p>
                <h3 class="mt-3 text-xl font-semibold">{{ $package->name }}</h3>
                @if ($package->description)
                    <p class="mt-2 line-clamp-3 text-sm text-muted">{{ $package->description }}</p>
                @endif
                <div class="mt-auto flex items-end justify-between pt-6">
                    <p class="font-display text-2xl">{{ money($package->effectivePrice()) }}</p>
                    @if ($package->savings() > 0)
                        <span class="rounded-full bg-success/10 px-3 py-1 text-xs text-success">− {{ money($package->savings()) }}</span>
                    @endif
                </div>
            </a>
        @endforeach
    </div>
    <div class="mt-8 text-center"><a href="{{ url('/packages') }}" class="btn-ghost">{{ __('marketing.packages.public_title') }}</a></div>
@endif
