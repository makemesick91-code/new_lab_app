@php($current = (float) $current)
@php($minimum = (float) $minimum)

@if ($current <= 0)
    <x-ui.badge tone="danger">Habis</x-ui.badge>
@elseif ($current <= $minimum)
    <x-ui.badge tone="warning">Menipis</x-ui.badge>
@else
    <x-ui.badge tone="success">Aman</x-ui.badge>
@endif
