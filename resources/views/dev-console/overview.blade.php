<x-settings-shell title="Observability Console">
    @php
        $kpis = $data['kpis'];
        $hours = $data['window_hours'];
        $healthTone = fn (string $status) => match ($status) {
            'ok' => 'success',
            'degraded' => 'warning',
            'down' => 'danger',
            'not_in_use' => 'neutral',
            default => 'neutral',
        };
        $healthLabel = fn (string $status) => match ($status) {
            'ok' => 'GREEN',
            'degraded' => 'AMBER',
            'down' => 'RED',
            'not_in_use' => 'TIDAK DIPAKAI',
            default => 'UNKNOWN',
        };
    @endphp

    <div class="space-y-6">
        <x-ui.page-header title="DaengtisiaMS Observability Console"
            subtitle="Developer Assistance Console (ENT-7) — konsol observability teknis internal. Read-only, hanya Super Admin, setiap akses diaudit. Tidak menampilkan data medis maupun PII." />

        @include('dev-console.partials.nav')

        @unless ($data['telemetry_enabled'])
            <x-ui.alert variant="warning" title="Telemetri request nonaktif">
                OBSERVABILITY_TELEMETRY_ENABLED=false pada deployment ini — angka di bawah tidak bertambah.
            </x-ui.alert>
        @endunless

        <x-ui.card title="System Health" description="Probe ringan saat halaman dibuka (ENT-8 HealthCheckService). Tidak ada status yang di-hardcode.">
            <ul class="divide-y divide-hairline">
                @foreach ($data['health'] as $row)
                    <li class="flex items-center justify-between gap-4 py-2 text-sm" data-health="{{ \Illuminate\Support\Str::slug($row['name']) }}">
                        <div>
                            <p class="font-medium text-navy">{{ $row['name'] }}</p>
                            <p class="text-xs text-ink-soft">{{ $row['detail'] }}</p>
                        </div>
                        <x-ui.badge :tone="$healthTone($row['status'])">{{ $healthLabel($row['status']) }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <div>
            <p class="mb-2 text-xs text-ink-soft">Periode: {{ $hours }} jam terakhir · Online = request ≤ {{ intdiv((int) config('observability_console.presence.online_seconds'), 60) }} menit lalu.</p>
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
                <a href="{{ route('developer-console.live-users') }}" class="rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <x-ui.kpi-card label="Online Users" :value="number_format($kpis['online_users'])" :delta="$kpis['idle_users'].' idle'" />
                </a>
                <a href="{{ route('developer-console.errors') }}" class="rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <x-ui.kpi-card label="Errors" :value="number_format($kpis['errors'])" :delta="$kpis['server_errors'].' × 5xx'" />
                </a>
                <a href="{{ route('developer-console.slow-requests') }}" class="rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <x-ui.kpi-card label="Slow Requests" :value="number_format($kpis['slow_requests'])" :delta="$kpis['timeouts'].' timeout'" />
                </a>
                <a href="{{ route('developer-console.slow-queries') }}" class="rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <x-ui.kpi-card label="Slow Queries" :value="number_format($kpis['slow_queries'])" />
                </a>
                <a href="{{ route('developer-console.cache') }}" class="rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <x-ui.kpi-card label="App Cache Hit Rate" :value="$kpis['cache_hit_rate'] === null ? 'N/A' : $kpis['cache_hit_rate'].'%'" delta="request tercatat" />
                </a>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            @include('dev-console.partials.bar-chart', ['title' => 'Errors per jam', 'unit' => '', 'series' => $data['trends']['errors']])
            @include('dev-console.partials.bar-chart', ['title' => 'Slow requests per jam', 'unit' => '', 'series' => $data['trends']['slow_requests']])
            @include('dev-console.partials.bar-chart', ['title' => 'Slow queries per jam', 'unit' => '', 'series' => $data['trends']['slow_queries']])
            @include('dev-console.partials.bar-chart', ['title' => 'Response time p95 per jam', 'unit' => ' ms', 'series' => $data['trends']['p95_ms'], 'isLevel' => true,
                'description' => 'Persentil 95 durasi request tercatat (request terautentikasi + error/slow tamu).'
                    .($data['trends']['p95_sampled'] ? ' SAMPEL: hanya '.number_format((int) config('observability_console.trend.max_latency_samples')).' request terbaru — jam lama bisa kurang terwakili.' : '')])
        </div>
    </div>
</x-settings-shell>
