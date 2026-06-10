@props([
    'caption' => null,
])

<div class="ui-table-shell overflow-x-auto">
    <table {{ $attributes->merge(['class' => 'min-w-full divide-y divide-gray-200 text-sm']) }}>
        @if ($caption)
            <caption class="px-4 py-3 text-left text-sm font-medium text-gray-900">{{ $caption }}</caption>
        @endif
        {{ $slot }}
    </table>
</div>
