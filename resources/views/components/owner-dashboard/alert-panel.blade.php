@props([
    'alerts' => [],
    'title' => 'Pusat Peringatan',
    'emptyTitle' => 'Tidak ada peringatan mendesak',
    'emptyBody' => 'Operasional masih dalam ambang batas yang dikonfigurasi untuk tampilan ini.',
])

@php
    $alerts = collect($alerts);
    $badgeClasses = [
        'critical' => 'bg-rose-50 text-rose-700 ring-rose-100',
        'danger' => 'bg-rose-50 text-rose-700 ring-rose-100',
        'warning' => 'bg-amber-50 text-amber-700 ring-amber-100',
        'info' => 'bg-sky-50 text-sky-700 ring-sky-100',
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        'neutral' => 'bg-gray-100 text-gray-600 ring-gray-200',
    ];
@endphp

<x-owner-dashboard.dashboard-section :title="$title" description="Pengecualian kritis yang perlu perhatian owner." density="compact">
    @if ($alerts->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center">
            <p class="text-sm font-medium text-gray-900">{{ $emptyTitle }}</p>
            <p class="mt-1 text-sm text-gray-500">{{ $emptyBody }}</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($alerts as $alert)
                @php
                    $severity = data_get($alert, 'severity', 'neutral');
                    $classes = $badgeClasses[$severity] ?? $badgeClasses['neutral'];
                @endphp
                <div class="rounded-lg border border-gray-200 p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $classes }}">
                                {{ ucfirst($severity) }}
                            </span>
                            <p class="mt-2 text-sm font-medium text-gray-900">{{ data_get($alert, 'title', 'Peringatan') }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ data_get($alert, 'description', '') }}</p>
                        </div>
                        @if (data_get($alert, 'metric'))
                            <p class="shrink-0 text-right text-sm font-semibold tabular-nums text-gray-900">{{ data_get($alert, 'metric') }}</p>
                        @endif
                    </div>
                    @if (data_get($alert, 'href'))
                        <a href="{{ data_get($alert, 'href') }}" class="mt-3 inline-flex text-xs font-semibold text-teal-700 hover:text-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                            Buka
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-owner-dashboard.dashboard-section>
