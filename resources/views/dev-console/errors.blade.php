<x-settings-shell title="Observability Console — Errors">
    <div class="space-y-6">
        <x-ui.page-header title="Errors"
            subtitle="Respons 4xx/5xx dan exception aplikasi. Validasi web (302 kembali) dicatat sebagai 422 dan akses tanpa login (302 ke login) sebagai 401 — status respons asli tetap disimpan. Pesan exception disanitasi; isi request tidak pernah disimpan." />
        @include('dev-console.partials.nav')
        @include('dev-console.partials.filters', ['action' => route('developer-console.errors'), 'fields' => ['status', 'user', 'branch']])

        <x-ui.card>
            @if ($events->isEmpty())
                <x-ui.empty-state title="Tidak ada error" description="Tidak ada error yang cocok dengan filter dalam retensi {{ intdiv((int) config('observability_console.retention.error_hours'), 24) }} hari." />
            @else
                <x-ui.table>
                    <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                        <tr><th class="px-4 py-2">Waktu</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">User</th><th class="px-4 py-2">Role</th><th class="px-4 py-2">Cabang</th><th class="px-4 py-2">Route</th><th class="px-4 py-2">Aktivitas</th><th class="px-4 py-2 text-right">Durasi</th><th class="px-4 py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($events as $event)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2 text-xs"><x-dev-console.time :at="$event->occurred_at" format="d M H:i:s" /></td>
                                <td class="px-4 py-2"><x-ui.badge :tone="$event->status_code >= 500 ? 'danger' : 'warning'">{{ $event->status_code }}</x-ui.badge></td>
                                <td class="px-4 py-2 text-navy">{{ $event->user_id ? ($labels['users'][$event->user_id] ?? 'User #'.$event->user_id) : 'Guest' }}</td>
                                <td class="px-4 py-2 text-ink-soft">{{ $event->user_role ?? '—' }}</td>
                                <td class="px-4 py-2 text-ink-soft">{{ $labels['branches'][$event->branch_id] ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $event->route_name ?? $event->path_template }}</td>
                                <td class="px-4 py-2 text-xs text-ink-soft">{{ $event->activity }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-xs">{{ number_format($event->duration_ms) }} ms</td>
                                <td class="px-4 py-2 text-right"><a href="{{ route('developer-console.errors.show', $event) }}" class="text-xs font-medium text-brand-700 hover:text-brand-800">Detail</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
                <div class="mt-4">{{ $events->links() }}</div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
