<x-settings-shell title="Developer Console">
    @php
        $fmtBytes = function ($bytes) {
            if ($bytes === null) return '—';
            if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 1).' GB';
            if ($bytes >= 1048576) return number_format($bytes / 1048576, 1).' MB';
            if ($bytes >= 1024) return number_format($bytes / 1024, 1).' KB';
            return $bytes.' B';
        };
        $runtime = $sections['runtime_health'] ?? [];
        $storage = $sections['storage_health'] ?? [];
        $disk = $sections['disk_backup'] ?? [];
        $log = $sections['application_log'] ?? [];
        $failed = $sections['failed_jobs'] ?? [];
        $audit = $sections['audit_events'] ?? [];
        $slow = $sections['slow_queries'] ?? [];
        $evidence = $sections['deploy_evidence'] ?? [];
    @endphp

    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">ENT-7 — Enterprise Foundation</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Developer Assistance Console</h2>
            <p class="mt-1 text-sm text-gray-500">Konsol diagnostik read-only. Semua akses diaudit; PII &amp; rahasia dimasking otomatis.</p>
        </div>

        {{-- Runtime health --}}
        <x-ui.card title="Kesehatan Runtime" description="Driver, koneksi, dan status antrian saat ini.">
            @if (($runtime['status'] ?? '') === 'ok')
                <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div><dt class="text-gray-500">Database</dt><dd class="font-medium text-gray-900">{{ $runtime['db_connection'] }} ({{ $runtime['db_ping_ms'] }} ms)</dd></div>
                    <div><dt class="text-gray-500">Cache</dt><dd class="font-medium text-gray-900">{{ $runtime['cache_driver'] }}</dd></div>
                    <div><dt class="text-gray-500">Session</dt><dd class="font-medium text-gray-900">{{ $runtime['session_driver'] }}</dd></div>
                    <div><dt class="text-gray-500">Queue</dt><dd class="font-medium text-gray-900">{{ $runtime['queue_connection'] }}</dd></div>
                    <div><dt class="text-gray-500">Job Pending</dt><dd class="font-medium text-gray-900">{{ $runtime['pending_jobs'] ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Job Gagal</dt><dd class="font-medium text-gray-900">{{ $runtime['failed_jobs'] ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Object Storage</dt><dd class="font-medium text-gray-900">{{ ($runtime['object_storage_enabled'] ?? false) ? 'Aktif' : 'Nonaktif' }}</dd></div>
                    <div><dt class="text-gray-500">Environment</dt><dd class="font-medium text-gray-900">{{ $runtime['environment'] }} / debug {{ ($runtime['debug'] ?? false) ? 'ON' : 'OFF' }}</dd></div>
                </dl>
            @else
                <p class="text-sm text-gray-500">Tidak tersedia.</p>
            @endif
        </x-ui.card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Storage health --}}
            <x-ui.card title="Status Izin Storage" description="Path wajib-tulis (STATELESS-1).">
                @if (($storage['status'] ?? '') === 'ok')
                    <ul class="space-y-2 text-sm">
                        @foreach ($storage['paths'] as $path)
                            <li class="flex items-center justify-between">
                                <span class="text-gray-700">{{ $path['path'] }}</span>
                                <x-ui.badge :tone="$path['writable'] ? 'success' : 'danger'">{{ $path['writable'] ? 'Writable' : 'Tidak writable' }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-gray-500">Tidak tersedia.</p>
                @endif
            </x-ui.card>

            {{-- Disk & backup --}}
            <x-ui.card title="Disk &amp; Backup" description="Kapasitas disk dan backup deploy terakhir.">
                @if (($disk['status'] ?? '') === 'ok')
                    <p class="text-sm text-gray-700">
                        Disk: {{ $fmtBytes($disk['disk_free_bytes']) }} bebas dari {{ $fmtBytes($disk['disk_total_bytes']) }}
                    </p>
                    <ul class="mt-3 space-y-1 text-sm text-gray-600">
                        @forelse ($disk['latest_backups'] as $backup)
                            <li>{{ $backup['name'] }} — {{ $fmtBytes($backup['size_bytes']) }} ({{ $backup['modified_at'] }})</li>
                        @empty
                            <li class="text-gray-400">Belum ada file backup terdeteksi.</li>
                        @endforelse
                    </ul>
                @else
                    <p class="text-sm text-gray-500">Tidak tersedia.</p>
                @endif
            </x-ui.card>
        </div>

        {{-- Failed jobs --}}
        <x-ui.card title="Job Gagal" description="Ringkasan failed_jobs (ENT-5).">
            @if (($failed['status'] ?? '') === 'ok' && ($failed['table_exists'] ?? false))
                <p class="text-sm text-gray-700">Total: <span class="font-semibold">{{ $failed['total'] }}</span></p>
                @if ($failed['recent'] !== [])
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead><tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                                <th class="py-2 pr-4">UUID</th><th class="py-2 pr-4">Queue</th><th class="py-2 pr-4">Gagal Pada</th><th class="py-2">Exception</th>
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($failed['recent'] as $job)
                                    <tr>
                                        <td class="py-2 pr-4 font-mono text-xs">{{ $job['uuid'] }}</td>
                                        <td class="py-2 pr-4">{{ $job['connection'] }}/{{ $job['queue'] }}</td>
                                        <td class="py-2 pr-4">{{ $job['failed_at'] }}</td>
                                        <td class="py-2 text-gray-600">{{ $job['exception_excerpt'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @else
                <p class="text-sm text-gray-500">Tidak tersedia.</p>
            @endif
        </x-ui.card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Audit events --}}
            <x-ui.card title="Audit Events Terbaru" description="Metadata sys_audit_logs (tanpa payload).">
                @if (($audit['status'] ?? '') === 'ok' && ($audit['table_exists'] ?? false))
                    <p class="text-sm text-gray-700">Total: <span class="font-semibold">{{ $audit['total'] }}</span></p>
                    <ul class="mt-3 space-y-1 text-sm text-gray-600">
                        @forelse ($audit['recent'] as $event)
                            <li>{{ $event['performed_at'] }} — {{ $event['action'] }} ({{ $event['entity_type'] }}@if($event['entity_id'])#{{ $event['entity_id'] }}@endif)</li>
                        @empty
                            <li class="text-gray-400">Belum ada audit event.</li>
                        @endforelse
                    </ul>
                @else
                    <p class="text-sm text-gray-500">Tidak tersedia.</p>
                @endif
            </x-ui.card>

            {{-- Slow queries --}}
            <x-ui.card title="Ringkasan Slow Query" description="pg_stat_statements (NSF-3), query ternormalisasi.">
                @if (($slow['status'] ?? '') === 'ok' && ($slow['available'] ?? false))
                    <ul class="space-y-2 text-sm text-gray-600">
                        @forelse ($slow['top_queries'] as $query)
                            <li>
                                <span class="font-mono text-xs">{{ $query['query_summary'] }}</span>
                                <span class="text-gray-400">— {{ $query['calls'] }} calls, mean {{ number_format($query['mean_time_ms'], 1) }} ms</span>
                            </li>
                        @empty
                            <li class="text-gray-400">Belum ada data slow query.</li>
                        @endforelse
                    </ul>
                @else
                    <p class="text-sm text-gray-500">Tidak tersedia pada driver/instalasi saat ini.</p>
                @endif
            </x-ui.card>
        </div>

        {{-- Deploy evidence --}}
        <x-ui.card title="Deploy Evidence" description="Artefak release-evidence terakhir.">
            @if (($evidence['status'] ?? '') === 'ok' && ($evidence['directory_exists'] ?? false))
                @if ($evidence['governance_decision'])
                    <p class="text-sm text-gray-700">Keputusan governance terakhir:
                        <x-ui.badge :tone="$evidence['governance_decision'] === 'GO' ? 'success' : 'warning'">{{ $evidence['governance_decision'] }}</x-ui.badge>
                    </p>
                @endif
                <ul class="mt-3 grid grid-cols-1 gap-1 text-sm text-gray-600 sm:grid-cols-2">
                    @forelse ($evidence['files'] as $file)
                        <li>{{ $file['name'] }} — {{ $fmtBytes($file['size_bytes']) }} ({{ $file['modified_at'] }})</li>
                    @empty
                        <li class="text-gray-400">Belum ada artefak evidence.</li>
                    @endforelse
                </ul>
            @else
                <p class="text-sm text-gray-500">Tidak tersedia.</p>
            @endif
        </x-ui.card>

        {{-- Application log --}}
        <x-ui.card title="Log Aplikasi (Masked)" description="Baris terakhir log aplikasi; PII dan rahasia dimasking.">
            @if (($log['status'] ?? '') === 'ok' && ($log['exists'] ?? false))
                <p class="text-sm text-gray-700">
                    Ukuran: {{ $fmtBytes($log['size_bytes']) }} — baris ERROR/CRITICAL pada tail: <span class="font-semibold">{{ $log['error_count'] }}</span>
                </p>
                <pre class="mt-3 max-h-96 overflow-auto rounded-lg bg-gray-900 p-4 text-xs leading-relaxed text-gray-100">@foreach ($log['lines'] as $line){{ $line }}
@endforeach</pre>
            @else
                <p class="text-sm text-gray-500">File log tidak ditemukan.</p>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
