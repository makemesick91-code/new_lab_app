@php
    $toneMap = ['match' => 'success', 'conflict' => 'danger', 'missing' => 'warning'];
    $labelMap = ['match' => 'MATCH', 'conflict' => 'CONFLICT', 'missing' => 'MISSING'];
@endphp
<x-ui.badge :tone="$toneMap[$status] ?? 'neutral'">{{ $labelMap[$status] ?? strtoupper((string) $status) }}</x-ui.badge>
