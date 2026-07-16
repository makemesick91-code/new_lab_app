{{-- SATUSEHAT-2 — Batch detail + controlled queue action. SANDBOX only. NIK
     never shown. The "Kirim ke Sandbox" action is guarded by the send permission
     AND the runtime kill switch; while disabled it is refused server-side. --}}
<x-settings-shell title="SATUSEHAT — Batch #{{ $batch->id }}">
    <x-ui.page-header
        title="Batch Pengiriman #{{ $batch->id }}"
        subtitle="Resource FHIR yang disiapkan untuk satu batch. Setiap pengiriman dicatat pada audit.">
        <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button :href="route('satusehat.submissions.batches.index')" variant="secondary">Kembali</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert variant="warning" title="Lingkungan {{ strtoupper($environment) }} — BUKAN DATA PRODUKSI">
        Status batch: <strong>{{ $batch->statusLabel() }}</strong>.
        Kill switch pengiriman: <strong>{{ $sendEnabled ? 'AKTIF' : 'NONAKTIF' }}</strong>.
    </x-ui.alert>

    @if ($canSend)
        <x-ui.card class="mb-4">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="font-medium text-navy">Kirim batch ke sandbox SATUSEHAT</p>
                    <p class="text-sm text-ink-soft">
                        Hanya resource yang siap yang dikirim, berurutan (Encounter lalu turunannya).
                        @unless ($sendEnabled) Pengiriman saat ini <strong>nonaktif</strong> dan akan ditolak. @endunless
                    </p>
                </div>
                <form method="POST" action="{{ route('satusehat.submissions.batches.queue', $batch) }}"
                      onsubmit="return confirm('Kirim batch ini ke sandbox SATUSEHAT?');">
                    @csrf
                    <x-ui.button type="submit" variant="primary" :disabled="! $sendEnabled">Kirim ke Sandbox</x-ui.button>
                </form>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card title="Resource dalam batch">
        <x-ui.table>
            <thead class="bg-navy-50 text-ink">
                <tr>
                    <th class="px-4 py-2 text-left">#</th>
                    <th class="px-4 py-2 text-left">Resource</th>
                    <th class="px-4 py-2 text-left">Urutan</th>
                    <th class="px-4 py-2 text-left">Status</th>
                    <th class="px-4 py-2 text-left">HTTP</th>
                    <th class="px-4 py-2 text-left">Remote ID</th>
                    <th class="px-4 py-2 text-left">Percobaan</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @foreach ($batch->items->sortBy('dependency_order') as $item)
                    <tr>
                        <td class="px-4 py-2">{{ $item->id }}</td>
                        <td class="px-4 py-2">{{ $item->resource_type }}</td>
                        <td class="px-4 py-2">{{ $item->dependency_order }}</td>
                        <td class="px-4 py-2"><x-ui.badge tone="neutral">{{ $item->status }}</x-ui.badge></td>
                        <td class="px-4 py-2">{{ $item->http_status ?? '—' }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $item->remote_resource_id ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $item->attempt_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Riwayat Audit" class="mt-4">
        <x-ui.table>
            <thead class="bg-navy-50 text-ink">
                <tr>
                    <th class="px-4 py-2 text-left">Waktu</th>
                    <th class="px-4 py-2 text-left">Peristiwa</th>
                    <th class="px-4 py-2 text-left">Ringkasan</th>
                    <th class="px-4 py-2 text-left">Aktor</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @forelse ($timeline as $log)
                    <tr>
                        <td class="px-4 py-2">{{ $log->created_at?->format('d M Y H:i:s') }}</td>
                        <td class="px-4 py-2">{{ $log->event }}</td>
                        <td class="px-4 py-2">{{ $log->summary }}</td>
                        <td class="px-4 py-2">{{ $log->actor?->name ?? 'Sistem' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6"><x-ui.empty-state title="Belum ada aktivitas" /></td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>
</x-settings-shell>
