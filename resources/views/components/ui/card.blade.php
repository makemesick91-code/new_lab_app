@props([
    'title' => null,
    'description' => null,
    'padding' => 'p-6',
])

<div {{ $attributes->merge(['class' => "ui-card {$padding}"]) }}>
    @if ($title || $description)
        <div class="mb-4">
            @if ($title)
                <h3 class="text-base font-semibold text-gray-900">{{ $title }}</h3>
            @endif
            @if ($description)
                <p class="mt-1 text-sm text-gray-500">{{ $description }}</p>
            @endif
        </div>
    @endif

    {{ $slot }}
</div>
