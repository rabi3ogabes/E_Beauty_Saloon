@php($today = $branch->hoursFor(now($branch->timezone)))
@if ($today)
    <span dir="ltr">{{ $today['open'] }} – {{ $today['close'] }}</span>
@else
    {{ __('site.home.closed_now') }}
@endif
