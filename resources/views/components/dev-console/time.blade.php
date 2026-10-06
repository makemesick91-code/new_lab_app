@props(['at' => null, 'format' => 'd M Y H:i:s'])
{{-- Telemetry is stored in the application timezone (UTC); it is DISPLAYED
     in clinic-local time and labelled, so a reader never has to guess. --}}
@if ($at)
    <time datetime="{{ $at->toIso8601String() }}" {{ $attributes }}>{{ $at->copy()->setTimezone(config('clinical.timezone', 'Asia/Makassar'))->format($format) }} WITA</time>
@else
    <span class="text-ink-muted">—</span>
@endif
