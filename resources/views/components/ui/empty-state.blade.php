@props([
    'title' => 'Belum ada data',
    'description' => null,
    'icon' => true,
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-xl border border-dashed border-hairline bg-surface px-6 py-12 text-center']) }}>
    @if ($icon)
        <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-navy-50 text-ink-muted">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
            </svg>
        </div>
    @endif
    <p class="text-sm font-semibold text-navy">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-ink-soft">{{ $description }}</p>
    @endif
    @isset($action)
        <div class="mt-4">{{ $action }}</div>
    @endisset
</div>
