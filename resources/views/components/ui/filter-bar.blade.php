@props([
    'action' => null,
    'method' => 'GET',
])

{{--
    Reusable filter/search bar. Wrap filter fields (x-ui.input/select) in the
    default slot; optional `actions` slot for submit/reset buttons.
--}}
@if ($action)
    <form method="{{ $method }}" action="{{ $action }}" {{ $attributes->merge(['class' => 'mb-4 rounded-xl border border-hairline bg-surface p-4 shadow-card']) }}>
        <div class="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-end">
            {{ $slot }}
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    </form>
@else
    <div {{ $attributes->merge(['class' => 'mb-4 rounded-xl border border-hairline bg-surface p-4 shadow-card']) }}>
        <div class="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-end">
            {{ $slot }}
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    </div>
@endif
