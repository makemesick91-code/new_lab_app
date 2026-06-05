@props([
    'events' => [],
    'title' => 'Aktivitas Terbaru',
    'emptyTitle' => 'Belum ada aktivitas terbaru',
])

@php
    $events = collect($events);
    $markerClasses = [
        'critical' => 'bg-rose-500',
        'danger' => 'bg-rose-500',
        'warning' => 'bg-amber-500',
        'success' => 'bg-emerald-500',
        'info' => 'bg-sky-500',
        'neutral' => 'bg-gray-300',
    ];
@endphp

<x-owner-dashboard.dashboard-section :title="$title" description="Aktivitas operasional terbaru dari order, QC, pengiriman, keuangan, dan persediaan.">
    @if ($events->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center">
            <p class="text-sm font-medium text-gray-900">{{ $emptyTitle }}</p>
            <p class="mt-1 text-sm text-gray-500">Aktivitas akan tampil di sini saat data terhubung ke dasbor.</p>
        </div>
    @else
        <ol class="relative space-y-4 border-l border-gray-200 pl-4">
            @foreach ($events as $event)
                @php
                    $severity = data_get($event, 'severity', 'neutral');
                    $marker = $markerClasses[$severity] ?? $markerClasses['neutral'];
                @endphp
                <li class="relative">
                    <span class="absolute -left-[1.3125rem] mt-1.5 h-2.5 w-2.5 rounded-full {{ $marker }}"></span>
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ data_get($event, 'title', 'Aktivitas') }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ data_get($event, 'description', '') }}</p>
                        </div>
                        <p class="text-xs text-gray-500">{{ data_get($event, 'occurredAt', '') }}</p>
                    </div>
                    @if (data_get($event, 'href'))
                        <a href="{{ data_get($event, 'href') }}" class="mt-2 inline-flex text-xs font-semibold text-teal-700 hover:text-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                            Buka
                        </a>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</x-owner-dashboard.dashboard-section>
