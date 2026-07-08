@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'value' => null,
    'error' => null,
    'help' => null,
    'required' => false,
])

@php
    $__bag = $errors ?? null;
    $error = $error ?? ($name && $__bag ? ($__bag->first($name) ?: null) : null);
    $id = $attributes->get('id', $name);
    $ring = $error
        ? 'border-danger focus:border-danger focus:ring-danger'
        : 'border-hairline focus:border-brand-500 focus:ring-brand-500';
    // UIX-17 — associate the visible error/help text with the field so screen
    // readers announce it. Only wired when the field has an id (native semantics).
    $describedBy = $id ? ($error ? $id.'-error' : ($help ? $id.'-help' : null)) : null;
@endphp

<div class="space-y-1.5">
    @if ($label)
        <label @if ($id) for="{{ $id }}" @endif class="block text-sm font-medium text-navy">
            {{ $label }}@if ($required)<span class="text-danger"> *</span>@endif
        </label>
    @endif

    <input
        type="{{ $type }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($id) id="{{ $id }}" @endif
        @if ($value !== null) value="{{ $value }}" @endif
        @if ($required) required aria-required="true" @endif
        @if ($error) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->merge(['class' => "block w-full rounded-lg bg-surface px-3 py-2 text-sm text-navy placeholder:text-ink-muted shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-0 disabled:bg-navy-50 disabled:text-ink-muted {$ring}"]) }}
    />

    @if ($error)
        <p @if ($id) id="{{ $id }}-error" @endif class="text-xs text-danger">{{ $error }}</p>
    @elseif ($help)
        <p @if ($id) id="{{ $id }}-help" @endif class="text-xs text-ink-soft">{{ $help }}</p>
    @endif
</div>
