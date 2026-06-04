@props([
    'title',
    'description' => null,
    'actionLabel' => null,
    'actionHref' => null,
    'density' => 'normal',
])

@php($padding = $density === 'compact' ? 'p-4' : 'p-6')

<section {{ $attributes->merge(['class' => "rounded-lg border border-gray-200 bg-white shadow-sm {$padding}"]) }}>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-base font-semibold text-gray-900">{{ $title }}</h3>
            @if ($description)
                <p class="mt-1 text-sm text-gray-500">{{ $description }}</p>
            @endif
        </div>

        @if ($actionLabel && $actionHref)
            <a href="{{ $actionHref }}" class="text-sm font-medium text-teal-700 hover:text-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                {{ $actionLabel }}
            </a>
        @endif
    </div>

    <div class="mt-4">
        {{ $slot }}
    </div>
</section>
