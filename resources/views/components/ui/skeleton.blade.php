@props([
    'lines' => 1,
    'circle' => false,
    'height' => 'h-4',
])

@if ($circle)
    <div {{ $attributes->merge(['class' => 'animate-pulse rounded-full bg-navy-100 '.$height]) }}></div>
@elseif ((int) $lines <= 1)
    <div {{ $attributes->merge(['class' => 'animate-pulse rounded bg-navy-100 '.$height]) }}></div>
@else
    <div {{ $attributes->merge(['class' => 'space-y-2']) }}>
        @for ($i = 0; $i < (int) $lines; $i++)
            <div class="animate-pulse rounded bg-navy-100 {{ $height }} {{ $i === (int) $lines - 1 ? 'w-2/3' : 'w-full' }}"></div>
        @endfor
    </div>
@endif
