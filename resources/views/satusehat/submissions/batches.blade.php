{{-- SATUSEHAT-2 — Outbound submission batches. SANDBOX only. NIK is never shown.
     No "send all"; queuing a batch is an explicit per-batch action. --}}
<x-settings-shell title="SATUSEHAT — Batch Pengiriman">
    <x-ui.page-header
        title="Batch Pengiriman SATUSEHAT"
        subtitle="Batch yang disiapkan dari kandidat yang disetujui. Pengiriman ke sandbox bersifat eksplisit dan terkontrol.">
        <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button :href="route('satusehat.submissions.index')" variant="secondary">Kembali ke Filter</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert variant="warning" title="Lingkungan {{ strtoupper($environment) }} — BUKAN DATA PRODUKSI">
        Pengiriman eksternal (kill switch): <strong>{{ $sendEnabled ? 'AKTIF' : 'NONAKTIF' }}</strong>.
        Selama nonaktif, batch dapat disiapkan namun tidak dikirim.
    </x-ui.alert>

    <x-ui.card>
        <x-ui.table>
            <thead class="bg-navy-50 text-ink">
                <tr>
                    <th class="px-4 py-2 text-left">#</th>
                    <th class="px-4 py-2 text-left">Cabang</th>
                    <th class="px-4 py-2 text-left">Status</th>
                    <th class="px-4 py-2 text-left">Resource</th>
                    <th class="px-4 py-2 text-left">Terkirim / Gagal / Rekonsiliasi</th>
                    <th class="px-4 py-2 text-left">Dibuat</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @forelse ($batches as $batch)
                    <tr>
                        <td class="px-4 py-2">{{ $batch->id }}</td>
                        <td class="px-4 py-2">{{ $batch->branch?->name }}</td>
                        <td class="px-4 py-2"><x-ui.badge :tone="$batch->statusTone()">{{ $batch->statusLabel() }}</x-ui.badge></td>
                        <td class="px-4 py-2">{{ $batch->items_count }}</td>
                        <td class="px-4 py-2">{{ $batch->succeeded_count }} / {{ $batch->failed_count }} / {{ $batch->unknown_count }}</td>
                        <td class="px-4 py-2">{{ $batch->created_at?->format('d M Y H:i') }}</td>
                        <td class="px-4 py-2"><x-ui.button size="sm" :href="route('satusehat.submissions.batches.show', $batch)">Detail</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6"><x-ui.empty-state title="Belum ada batch" description="Siapkan kandidat yang disetujui dari halaman filter." /></td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>

    <div class="mt-4">{{ $batches->links() }}</div>
</x-settings-shell>
