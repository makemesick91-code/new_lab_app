@props([
    'title',
    'description' => null,
    'actionLabel' => null,
    'actionHref' => null,
    'density' => 'normal',
])

@php
    $padding = $density === 'compact' ? 'p-4' : 'p-6';
@endphp

<section {{ $attributes->merge(['class' => "ui-card {$padding}"]) }}>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-base font-semibold text-navy">{{ $title }}</h3>
            @if ($description)
                <p class="mt-1 text-sm text-ink-soft">{{ $description }}</p>
            @endif
        </div>

        @if ($actionLabel && $actionHref)
            <a href="{{ $actionHref }}" class="text-sm font-medium text-brand-700 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                {{ $actionLabel }}
            </a>
        @endif
    </div>

    <div class="mt-4">
        {{ $slot }}
    </div>
</section>
