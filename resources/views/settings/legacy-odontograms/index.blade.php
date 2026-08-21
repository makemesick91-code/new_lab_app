{{--
    FIX-04b — staged legacy ODONTOGRAM charts.

    Presentation only. Every row was already branch-scoped server-side, and
    KTP/NIK is never rendered here (or anywhere in this workspace).
--}}
<x-settings-shell title="Impor Arsip Odontogram Lama">
    <div class="space-y-6">
        <x-ui.page-header
            title="Impor Arsip Odontogram Lama"
            subtitle="Arsip PDF odontogram kertas milik pasien. Dokumen distaging dan dirender terlebih dahulu — belum menjadi bukti klinis final."
        >
            <x-slot:breadcrumb>Master Data RME / Impor Arsip Odontogram Lama</x-slot:breadcrumb>

            <x-slot:actions>
                @can('create', \App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport::class)
                    <x-ui.button :href="route('settings.rme.legacy-odontograms.create')">Unggah Arsip Baru</x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        <x-ui.alert variant="info" title="Arsip lama, bukan pemeriksaan berjalan">
            Dokumen di sini adalah odontogram <strong>lama</strong> milik pasien. Impor tidak membuat kunjungan,
            odontogram baru, rekam medis, tagihan, pembayaran, maupun order lab, dan tidak memengaruhi antrean
            atau laporan pendapatan.
        </x-ui.alert>

        <x-ui.filter-bar :action="route('settings.rme.legacy-odontograms.index')" method="GET">
            <x-ui.input
                name="patient"
                label="Pasien / Nomor RM"
                :value="$filters['patient']"
                placeholder="Nama atau Nomor RM"
            />
            <x-ui.select name="status" label="Status">
                <option value="">Semua status</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $status }}</option>
                @endforeach
            </x-ui.select>

            <x-slot:actions>
                <x-ui.button type="submit">Terapkan</x-ui.button>
                <x-ui.button variant="secondary" :href="route('settings.rme.legacy-odontograms.index')">Atur Ulang</x-ui.button>
            </x-slot:actions>
        </x-ui.filter-bar>

        <x-ui.card padding="p-0">
            @if ($imports->isEmpty())
                <div class="p-6">
                    <x-ui.empty-state
                        title="Belum ada arsip odontogram lama"
                        description="Unggah PDF odontogram lama pasien untuk mulai melakukan staging."
                    >
                        @can('create', \App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport::class)
                            <x-ui.button :href="route('settings.rme.legacy-odontograms.create')">Unggah Arsip Baru</x-ui.button>
                        @endcan
                    </x-ui.empty-state>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-hairline text-sm">
                        <thead class="bg-navy-50 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">
                            <tr>
                                <th class="px-4 py-3">Pasien</th>
                                <th class="px-4 py-3">Tanggal Odontogram Lama</th>
                                <th class="px-4 py-3">Cabang Asal</th>
                                <th class="px-4 py-3">Halaman</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($imports as $import)
                                <tr>
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-navy">{{ $import->patient?->name ?? '—' }}</div>
                                        <div class="text-xs text-ink-muted">{{ $import->patient?->medical_record_number ?? 'Belum ada RM' }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-ink">{{ $import->selected_odontogram_date?->format('d-m-Y') ?? '—' }}</td>
                                    <td class="px-4 py-3 text-ink">{{ $import->originBranch?->name ?? 'Tidak dicatat' }}</td>
                                    <td class="px-4 py-3 text-ink">{{ $import->page_count ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <x-ui.badge :status="$import->status">{{ $import->status }}</x-ui.badge>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <x-ui.button
                                            size="sm"
                                            variant="secondary"
                                            :href="route('settings.rme.legacy-odontograms.show', $import->getKey())"
                                        >Detail</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-hairline p-4">
                    {{ $imports->links() }}
                </div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
