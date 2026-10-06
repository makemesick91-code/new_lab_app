{{--
    FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — single-series hourly bar chart.

    Dataviz decisions: one series, so no legend box (the title names it); one
    hue (brand-600, validated against the canvas surface); bars anchored to a
    zero baseline with a 2px gap; every bar carries a hover title with its
    exact value; a table view is always available. No chart library.

    @param string $title
    @param string $unit    value suffix shown in tooltips/table
    @param list<array{label: string, value: int}> $series
--}}
@php
    $max = max(1, collect($series)->max('value'));
    $total = collect($series)->sum('value');
    $peak = collect($series)->sortByDesc('value')->first();
@endphp
<x-ui.card :title="$title" :description="$description ?? null">
    <div class="flex items-baseline justify-between text-xs text-ink-soft">
        <span>Puncak: <span class="font-semibold text-navy">{{ number_format($peak['value'] ?? 0) }}{{ $unit }}</span>@if (($peak['value'] ?? 0) > 0) · {{ $peak['label'] }} WITA @endif</span>
        @if (! ($isLevel ?? false))
            <span>Total: <span class="font-semibold text-navy">{{ number_format($total) }}</span></span>
        @endif
    </div>
    <div class="mt-3 flex h-32 items-end gap-0.5 border-b border-hairline" role="img"
         aria-label="{{ $title }}: puncak {{ $peak['value'] ?? 0 }}{{ $unit }}">
        @foreach ($series as $point)
            <div class="group relative flex h-full flex-1 items-end">
                <div class="w-full rounded-t-sm {{ $point['value'] > 0 ? 'bg-brand-600 group-hover:bg-brand-800' : 'bg-transparent' }}"
                     style="height: {{ $point['value'] > 0 ? max(4, round($point['value'] / $max * 100)) : 0 }}%"
                     title="{{ $point['label'] }} WITA — {{ number_format($point['value']) }}{{ $unit }}"></div>
            </div>
        @endforeach
    </div>
    <div class="mt-1 flex justify-between text-[0.65rem] text-ink-muted">
        <span>{{ $series[0]['label'] ?? '' }}</span>
        <span>{{ $series[intdiv(count($series), 2)]['label'] ?? '' }}</span>
        <span>{{ $series[count($series) - 1]['label'] ?? '' }} WITA</span>
    </div>
    <details class="mt-3 text-xs">
        <summary class="cursor-pointer text-ink-soft">Lihat sebagai tabel</summary>
        <table class="mt-2 w-full text-left">
            <thead><tr class="text-ink-muted"><th class="py-1 font-medium">Jam (WITA)</th><th class="py-1 text-right font-medium">Nilai</th></tr></thead>
            <tbody>
                @foreach ($series as $point)
                    <tr class="border-t border-hairline"><td class="py-1">{{ $point['label'] }}</td><td class="py-1 text-right tabular-nums">{{ number_format($point['value']) }}{{ $unit }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </details>
</x-ui.card>
