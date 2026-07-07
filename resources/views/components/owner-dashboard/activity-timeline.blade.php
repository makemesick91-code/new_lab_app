@props([
    'events' => [],
    'title' => 'Aktivitas Terbaru',
    'emptyTitle' => 'Belum ada aktivitas terbaru',
])

@php
    $events = collect($events);
    $markerClasses = [
        'critical' => 'bg-danger',
        'danger' => 'bg-danger',
        'warning' => 'bg-warning',
        'success' => 'bg-success',
        'info' => 'bg-info',
        'neutral' => 'bg-navy-100',
    ];
@endphp

<x-owner-dashboard.dashboard-section :title="$title" description="Aktivitas operasional terbaru dari order, QC, pengiriman, keuangan, dan persediaan.">
    @if ($events->isEmpty())
        <div class="rounded-lg border border-dashed border-hairline px-4 py-8 text-center">
            <p class="text-sm font-medium text-navy">{{ $emptyTitle }}</p>
            <p class="mt-1 text-sm text-ink-soft">Aktivitas akan tampil di sini saat data terhubung ke dasbor.</p>
        </div>
    @else
        <ol class="relative space-y-4 border-l border-hairline pl-4">
            @foreach ($events as $event)
                @php
                    $severity = data_get($event, 'severity', 'neutral');
                    $marker = $markerClasses[$severity] ?? $markerClasses['neutral'];
                @endphp
                <li class="relative">
                    <span class="absolute -left-[1.3125rem] mt-1.5 h-2.5 w-2.5 rounded-full {{ $marker }}"></span>
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-medium text-navy">{{ data_get($event, 'title', 'Aktivitas') }}</p>
                            <p class="mt-1 text-xs text-ink-soft">{{ data_get($event, 'description', '') }}</p>
                        </div>
                        <p class="text-xs text-ink-soft">{{ data_get($event, 'occurredAt', '') }}</p>
                    </div>
                    @if (data_get($event, 'href'))
                        <a href="{{ data_get($event, 'href') }}" class="mt-2 inline-flex text-xs font-semibold text-brand-700 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                            Buka
                        </a>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</x-owner-dashboard.dashboard-section>
