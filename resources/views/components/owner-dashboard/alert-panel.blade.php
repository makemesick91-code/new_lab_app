@props([
    'alerts' => [],
    'title' => 'Pusat Peringatan',
    'emptyTitle' => 'Tidak ada peringatan mendesak',
    'emptyBody' => 'Operasional masih dalam ambang batas yang dikonfigurasi untuk tampilan ini.',
])

@php
    $alerts = collect($alerts);
    $badgeClasses = [
        'critical' => 'bg-danger-50 text-danger-700 ring-danger-100',
        'danger' => 'bg-danger-50 text-danger-700 ring-danger-100',
        'warning' => 'bg-warning-50 text-warning-700 ring-warning-100',
        'info' => 'bg-info-50 text-info-700 ring-info-100',
        'success' => 'bg-success-50 text-success-700 ring-success-100',
        'neutral' => 'bg-navy-50 text-ink-soft ring-hairline',
    ];
@endphp

<x-owner-dashboard.dashboard-section :title="$title" description="Pengecualian kritis yang perlu perhatian owner." density="compact">
    @if ($alerts->isEmpty())
        <div class="rounded-lg border border-dashed border-hairline px-4 py-8 text-center">
            <p class="text-sm font-medium text-navy">{{ $emptyTitle }}</p>
            <p class="mt-1 text-sm text-ink-soft">{{ $emptyBody }}</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($alerts as $alert)
                @php
                    $severity = data_get($alert, 'severity', 'neutral');
                    $classes = $badgeClasses[$severity] ?? $badgeClasses['neutral'];
                @endphp
                <div class="rounded-lg border border-hairline p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $classes }}">
                                {{ ucfirst($severity) }}
                            </span>
                            <p class="mt-2 text-sm font-medium text-navy">{{ data_get($alert, 'title', 'Peringatan') }}</p>
                            <p class="mt-1 text-xs text-ink-soft">{{ data_get($alert, 'description', '') }}</p>
                        </div>
                        @if (data_get($alert, 'metric'))
                            <p class="shrink-0 text-right text-sm font-semibold tabular-nums text-navy">{{ data_get($alert, 'metric') }}</p>
                        @endif
                    </div>
                    @if (data_get($alert, 'href'))
                        <a href="{{ data_get($alert, 'href') }}" class="mt-3 inline-flex text-xs font-semibold text-brand-700 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                            Buka
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-owner-dashboard.dashboard-section>
