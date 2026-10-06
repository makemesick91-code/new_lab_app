<x-settings-shell title="Observability Console — Slow Requests">
    @php
        $latency = config('observability_console.latency');
        $categoryTone = fn (string $c) => match ($c) {
            'WATCH' => 'warning',
            'SLOW', 'VERY_SLOW' => 'danger',
            'TIMEOUT' => 'danger',
            default => 'neutral',
        };
        $nPlusOne = (int) config('observability_console.slow_query.n_plus_one_hint_query_count');
    @endphp
    <div class="space-y-6">
        <x-ui.page-header title="Slow Requests"
            subtitle="Durasi diukur dari awal bootstrap aplikasi sampai respons siap. NORMAL &lt; {{ $latency['watch_ms'] }} ms · WATCH ≥ {{ $latency['watch_ms'] }} ms · SLOW ≥ {{ $latency['slow_ms'] }} ms · VERY_SLOW ≥ {{ $latency['very_slow_ms'] }} ms · TIMEOUT = fatal batas waktu PHP." />
        @include('dev-console.partials.nav')

        <x-ui.alert variant="info" title="Batas observasi timeout (jujur)">
            Aplikasi hanya dapat melihat request yang <strong>mencapai Laravel</strong>. Fatal batas waktu PHP
            (max_execution_time = {{ $visibility['php_max_execution_time'] === '0' ? 'tanpa batas di CLI' : $visibility['php_max_execution_time'].' detik' }})
            dicatat sebagai TIMEOUT — {{ $visibility['captured_timeouts'] }} tercatat dalam retensi.
            Timeout proxy/upstream (nginx 499/502/504), request yang tidak pernah sampai ke PHP, dan RTO di sisi klien/jaringan
            <strong>tidak terlihat</strong> dari aplikasi; periksa log nginx untuk kategori tersebut.
        </x-ui.alert>

        @include('dev-console.partials.filters', ['action' => route('developer-console.slow-requests'), 'fields' => ['user', 'branch', 'category']])

        <x-ui.card>
            @if ($events->isEmpty())
                <x-ui.empty-state title="Tidak ada request lambat" description="Tidak ada request ≥ {{ $latency['watch_ms'] }} ms yang cocok dengan filter." />
            @else
                <x-ui.table>
                    <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                        <tr><th class="px-4 py-2">Waktu</th><th class="px-4 py-2">Halaman / Route</th><th class="px-4 py-2">User</th><th class="px-4 py-2">Cabang</th><th class="px-4 py-2">HTTP</th><th class="px-4 py-2">Status</th><th class="px-4 py-2 text-right">Total</th><th class="px-4 py-2 text-right">DB</th><th class="px-4 py-2 text-right">Query</th><th class="px-4 py-2">Kategori</th></tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($events as $event)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2 text-xs"><x-dev-console.time :at="$event->occurred_at" format="d M H:i:s" /></td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $event->route_name ?? $event->path_template }}</td>
                                <td class="px-4 py-2 text-navy">{{ $event->user_id ? ($labels['users'][$event->user_id] ?? 'User #'.$event->user_id) : 'Guest' }}</td>
                                <td class="px-4 py-2 text-ink-soft">{{ $labels['branches'][$event->branch_id] ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $event->method }}</td>
                                <td class="px-4 py-2 text-xs">{{ $event->status_code }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-xs font-semibold text-navy">{{ number_format($event->duration_ms) }} ms</td>
                                <td class="px-4 py-2 text-right tabular-nums text-xs">{{ number_format($event->db_time_ms) }} ms</td>
                                <td class="px-4 py-2 text-right tabular-nums text-xs">
                                    {{ number_format($event->query_count) }}
                                    @if ($event->query_count >= $nPlusOne)
                                        <span class="block text-[0.65rem] text-warning-700" title="Petunjuk, bukan diagnosis">kemungkinan N+1</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2"><x-ui.badge :tone="$categoryTone($event->latency_category)">{{ $event->latency_category }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
                <div class="mt-4">{{ $events->links() }}</div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
