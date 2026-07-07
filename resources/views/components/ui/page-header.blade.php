@props([
    'title' => null,
    'subtitle' => null,
])

<div {{ $attributes->merge(['class' => 'mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between']) }}>
    <div class="min-w-0">
        @isset($breadcrumb)
            <div class="mb-1 text-xs text-ink-soft">{{ $breadcrumb }}</div>
        @endisset
        @if ($title)
            <h1 class="truncate text-2xl font-bold tracking-tight text-navy">{{ $title }}</h1>
        @endif
        @if ($subtitle)
            <p class="mt-1 text-sm text-ink-soft">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
