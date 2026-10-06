<x-settings-shell title="Observability Console — Live Users">
    @php
        $presenceTone = fn (string $s) => match ($s) {
            'ONLINE' => 'success',
            'IDLE' => 'warning',
            default => 'neutral',
        };
    @endphp
    <div class="space-y-6">
        <x-ui.page-header title="Live Users"
            subtitle="Kehadiran diturunkan dari request terakhir (tanpa heartbeat browser): ONLINE ≤ {{ intdiv((int) config('observability_console.presence.online_seconds'), 60) }} menit, IDLE ≤ {{ intdiv((int) config('observability_console.presence.idle_seconds'), 60) }} menit, selebihnya OFFLINE. Logout langsung OFFLINE." />
        @include('dev-console.partials.nav')

        <div class="grid grid-cols-3 gap-4">
            <x-ui.kpi-card label="Online" :value="$presence['counts']['ONLINE']" />
            <x-ui.kpi-card label="Idle" :value="$presence['counts']['IDLE']" />
            <x-ui.kpi-card label="Offline ({{ config('observability_console.presence.window_hours') }} jam)" :value="$presence['counts']['OFFLINE']" />
        </div>

        <x-ui.card title="Pengguna" description="Pengguna yang tercatat dalam {{ config('observability_console.presence.window_hours') }} jam terakhir. Cabang = konteks kerja saat request (observasi, bukan otoritas).">
            @if ($presence['rows'] === [])
                <x-ui.empty-state title="Belum ada aktivitas tercatat" description="Belum ada request terautentikasi dalam jendela ini." />
            @else
                <x-ui.table>
                    <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                        <tr><th class="px-4 py-2">User</th><th class="px-4 py-2">Role</th><th class="px-4 py-2">Cabang</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Akses Terakhir</th><th class="px-4 py-2">Aktivitas Terakhir</th><th class="px-4 py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($presence['rows'] as $row)
                            <tr class="{{ $selectedUserId === $row['user_id'] ? 'bg-brand-50' : '' }}">
                                <td class="px-4 py-2 font-medium text-navy">{{ $row['user'] }}</td>
                                <td class="px-4 py-2 text-ink-soft">{{ $row['role'] ?? '—' }}</td>
                                <td class="px-4 py-2 text-ink-soft">{{ $row['branch'] ?? '—' }}</td>
                                <td class="px-4 py-2"><x-ui.badge :tone="$presenceTone($row['status'])">{{ $row['status'] }}</x-ui.badge></td>
                                <td class="px-4 py-2 font-mono text-xs text-ink-soft">{{ $row['activity'] }}</td>
                                <td class="px-4 py-2 text-xs text-ink-soft"><x-dev-console.time :at="$row['last_activity']" /></td>
                                <td class="px-4 py-2 text-right"><a class="text-xs font-medium text-brand-700 hover:text-brand-800" href="{{ route('developer-console.live-users', ['user' => $row['user_id']]) }}">Riwayat</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        @if ($selectedUserId !== null)
            <x-ui.card title="Aktivitas terbaru" description="Maksimal {{ config('observability_console.presence.recent_activity_limit') }} request terakhir dalam retensi akses ({{ config('observability_console.retention.access_hours') }} jam). Hanya template route — tanpa id record, query string, atau isi request.">
                @if ($recent->isEmpty())
                    <x-ui.empty-state title="Tidak ada aktivitas" />
                @else
                    <x-ui.table>
                        <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                            <tr><th class="px-4 py-2">Waktu</th><th class="px-4 py-2">HTTP</th><th class="px-4 py-2">Route</th><th class="px-4 py-2">Status</th><th class="px-4 py-2 text-right">Durasi</th><th class="px-4 py-2">Request ID</th></tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($recent as $item)
                                <tr>
                                    <td class="px-4 py-2 text-xs"><x-dev-console.time :at="$item->occurred_at" format="H:i:s" /></td>
                                    <td class="px-4 py-2 font-mono text-xs">{{ $item->method }}</td>
                                    <td class="px-4 py-2 font-mono text-xs">{{ $item->route_name ?? $item->path_template }}</td>
                                    <td class="px-4 py-2"><x-ui.badge :tone="$item->is_error ? 'danger' : 'neutral'">{{ $item->status_code }}</x-ui.badge></td>
                                    <td class="px-4 py-2 text-right tabular-nums text-xs">{{ number_format($item->duration_ms) }} ms</td>
                                    <td class="px-4 py-2 font-mono text-[0.7rem] text-ink-muted">{{ $item->request_id }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                @endif
            </x-ui.card>
        @endif
    </div>
</x-settings-shell>
