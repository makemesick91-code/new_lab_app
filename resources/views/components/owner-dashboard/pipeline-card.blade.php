@props([
    'stages' => [],
    'title' => 'Pipeline Operasional',
    'periodLabel' => 'Periode saat ini',
])

@php
    $stages = collect($stages);
    $severityClasses = [
        'critical' => 'border-danger-100 bg-danger-50 text-danger-700',
        'danger' => 'border-danger-100 bg-danger-50 text-danger-700',
        'warning' => 'border-warning-100 bg-warning-50 text-warning-700',
        'success' => 'border-success-100 bg-success-50 text-success-700',
        'info' => 'border-info-100 bg-info-50 text-info-700',
        'neutral' => 'border-hairline bg-navy-50 text-ink',
    ];
@endphp

<x-owner-dashboard.dashboard-section :title="$title" :description="$periodLabel">
    @if ($stages->isEmpty())
        <div class="rounded-lg border border-dashed border-hairline px-4 py-8 text-center">
            <p class="text-sm font-medium text-navy">Belum ada data pipeline</p>
            <p class="mt-1 text-sm text-ink-soft">Tahap operasional akan tampil saat data dasbor terhubung.</p>
        </div>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-7">
            @foreach ($stages as $stage)
                @php
                    $severity = data_get($stage, 'severity', 'neutral');
                    $classes = $severityClasses[$severity] ?? $severityClasses['neutral'];
                    $href = data_get($stage, 'href');
                @endphp
                <div class="rounded-lg border p-3 {{ $classes }}">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm font-medium">{{ data_get($stage, 'label', 'Tahap') }}</p>
                        <p class="text-lg font-semibold tabular-nums">{{ format_number_id(data_get($stage, 'count', 0)) }}</p>
                    </div>
                    <div class="mt-3 h-1.5 rounded-full bg-white/70">
                        <div class="h-1.5 rounded-full bg-current" style="width: {{ max(0, min(100, (float) data_get($stage, 'percent', 0))) }}%"></div>
                    </div>
                    <p class="mt-1 text-xs opacity-80">{{ format_percent_id(data_get($stage, 'percent', 0)) }} dari pipeline</p>
                    <p class="mt-2 text-xs opacity-80">{{ data_get($stage, 'oldestAge', 'Belum ada data umur') }}</p>
                    @if ($href)
                        <a href="{{ $href }}" class="mt-2 inline-flex text-xs font-semibold underline-offset-2 hover:underline focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                            Lihat
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-owner-dashboard.dashboard-section>
