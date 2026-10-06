<x-settings-shell title="Observability Console — Slow Queries">
    @php $sq = config('observability_console.slow_query'); @endphp
    <div class="space-y-6">
        <x-ui.page-header title="Slow Queries"
            subtitle="Query ≥ {{ $sq['threshold_ms'] }} ms (CRITICAL ≥ {{ $sq['critical_ms'] }} ms) selama request HTTP. SQL disimpan ternormalisasi — setiap literal diganti '?', nilai binding tidak pernah disimpan ([REDACTED])." />
        @include('dev-console.partials.nav')
        @include('dev-console.partials.filters', ['action' => route('developer-console.slow-queries'), 'fields' => ['min_ms']])

        <x-ui.card title="Agregasi per fingerprint" description="Maksimal 5.000 sampel terbaru yang cocok filter, 20 fingerprint teratas (jumlah × rata-rata). Banyak kemunculan dalam sedikit request bisa menandakan N+1 — sebuah petunjuk, bukan diagnosis.">
            @if ($aggregates === [])
                <x-ui.empty-state title="Belum ada slow query" />
            @else
                <x-ui.table>
                    <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                        <tr><th class="px-4 py-2">Fingerprint</th><th class="px-4 py-2 text-right">Jumlah</th><th class="px-4 py-2 text-right">Request</th><th class="px-4 py-2 text-right">Rata-rata</th><th class="px-4 py-2 text-right">P95</th><th class="px-4 py-2 text-right">Maks</th><th class="px-4 py-2">Route</th></tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($aggregates as $agg)
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs"><a class="text-brand-700 hover:text-brand-800" href="{{ route('developer-console.slow-queries', ['fingerprint' => $agg['fingerprint']]) }}">{{ substr($agg['fingerprint'], 0, 12) }}</a></td>
                                <td class="px-4 py-2 text-right tabular-nums text-xs">{{ number_format($agg['count']) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-xs">{{ number_format($agg['requests']) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-xs">{{ number_format($agg['avg_ms']) }} ms</td>
                                <td class="px-4 py-2 text-right tabular-nums text-xs">{{ number_format($agg['p95_ms']) }} ms</td>
                                <td class="px-4 py-2 text-right tabular-nums text-xs font-semibold text-navy">{{ number_format($agg['max_ms']) }} ms</td>
                                <td class="px-4 py-2 font-mono text-[0.7rem] text-ink-soft">{{ implode(', ', $agg['routes']) ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        <x-ui.card title="Kejadian">
            @if ($queries->isEmpty())
                <x-ui.empty-state title="Tidak ada slow query yang cocok" />
            @else
                <x-ui.table>
                    <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                        <tr><th class="px-4 py-2">Waktu</th><th class="px-4 py-2 text-right">Durasi</th><th class="px-4 py-2">Query (ternormalisasi)</th><th class="px-4 py-2">Route</th><th class="px-4 py-2">User</th><th class="px-4 py-2">Cabang</th><th class="px-4 py-2">Request</th></tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($queries as $q)
                            <tr class="align-top">
                                <td class="whitespace-nowrap px-4 py-2 text-xs"><x-dev-console.time :at="$q->occurred_at" format="d M H:i:s" /></td>
                                <td class="px-4 py-2 text-right">
                                    <x-ui.badge :tone="$q->severity === 'CRITICAL' ? 'danger' : 'warning'">{{ number_format($q->duration_ms) }} ms</x-ui.badge>
                                </td>
                                <td class="max-w-xl px-4 py-2"><code class="block break-all font-mono text-[0.7rem] text-ink-soft">{{ \Illuminate\Support\Str::limit($q->sql_normalized, 300) }}</code>
                                    <span class="font-mono text-[0.65rem] text-ink-muted">{{ substr($q->fingerprint, 0, 12) }}</span></td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $q->route_name ?? '—' }}</td>
                                <td class="px-4 py-2 text-xs">{{ $q->user_id ? ($labels['users'][$q->user_id] ?? 'User #'.$q->user_id) : 'Guest' }}</td>
                                <td class="px-4 py-2 text-xs text-ink-soft">{{ $labels['branches'][$q->branch_id] ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-[0.65rem] text-ink-muted">{{ $q->request_id }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
                <div class="mt-4">{{ $queries->links() }}</div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
