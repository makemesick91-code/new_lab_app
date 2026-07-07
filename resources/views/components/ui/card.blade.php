@props([
    'title' => null,
    'description' => null,
    'padding' => 'p-6',
    'accent' => false,
])

{{-- accent = subtle premium gold left rail (use sparingly, e.g. revenue/KPI cards). --}}
<div {{ $attributes->merge(['class' => 'ui-card '.$padding.($accent ? ' relative overflow-hidden before:absolute before:inset-y-0 before:left-0 before:w-1 before:bg-gold' : '')]) }}>
    @if ($title || $description || isset($actions))
        <div class="mb-4 flex items-start justify-between gap-3">
            <div>
                @if ($title)
                    <h3 class="text-base font-semibold text-navy">{{ $title }}</h3>
                @endif
                @if ($description)
                    <p class="mt-1 text-sm text-ink-soft">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    {{ $slot }}
</div>
