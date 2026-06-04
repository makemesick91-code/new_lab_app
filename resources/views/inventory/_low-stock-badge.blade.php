@php($current = (float) $current)
@php($minimum = (float) $minimum)

@if ($current <= 0)
    <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">Out</span>
@elseif ($current <= $minimum)
    <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Low</span>
@else
    <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">OK</span>
@endif
