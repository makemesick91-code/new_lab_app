@props([
    'label' => null,
    'name' => null,
    'value' => null,
    'error' => null,
    'help' => null,
    'rows' => 4,
    'required' => false,
])

@php
    $__bag = $errors ?? null;
    $error = $error ?? ($name && $__bag ? ($__bag->first($name) ?: null) : null);
    $id = $attributes->get('id', $name);
    $ring = $error
        ? 'border-danger focus:border-danger focus:ring-danger'
        : 'border-hairline focus:border-brand-500 focus:ring-brand-500';
@endphp

<div class="space-y-1.5">
    @if ($label)
        <label @if ($id) for="{{ $id }}" @endif class="block text-sm font-medium text-navy">
            {{ $label }}@if ($required)<span class="text-danger"> *</span>@endif
        </label>
    @endif

    <textarea
        @if ($name) name="{{ $name }}" @endif
        @if ($id) id="{{ $id }}" @endif
        rows="{{ $rows }}"
        @if ($required) required aria-required="true" @endif
        @if ($error) aria-invalid="true" @endif
        {{ $attributes->merge(['class' => "block w-full rounded-lg bg-surface px-3 py-2 text-sm text-navy placeholder:text-ink-muted shadow-sm focus:outline-none focus:ring-2 disabled:bg-navy-50 disabled:text-ink-muted {$ring}"]) }}
    >{{ $value !== null ? $value : trim($slot) }}</textarea>

    @if ($error)
        <p class="text-xs text-danger">{{ $error }}</p>
    @elseif ($help)
        <p class="text-xs text-ink-soft">{{ $help }}</p>
    @endif
</div>
