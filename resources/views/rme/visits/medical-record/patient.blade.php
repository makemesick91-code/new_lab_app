{{--
    FEATURE-RME-MEDICAL-RECORDS-UNIFIED-NATIVE-LEGACY-1 — patient-keyed, read-only
    medical-record workspace.

    Works for a patient with ZERO native records: it never assumes a medical
    record, a clinic visit or a native sheet exists, and never fabricates one.
    Legacy archives open in their OWN canonical private viewers, which
    re-authorize every request; this page only links to them and never prints,
    streams or exposes a storage path. Never render a full NIK.
--}}
<x-settings-shell title="Rekam Medis Pasien">
    @php
        $statusLabels = [
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_DRAFT => 'Draft',
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL => 'Final',
        ];
        $statusTone = [
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_DRAFT => 'warning',
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL => 'success',
        ];
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            :title="$patient->name"
            subtitle="Rekam medis native dan arsip legacy pasien ini."
        >
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.medical-records.index')">Kembali ke Daftar</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.card title="Identitas Pasien">
            <dl class="grid grid-cols-1 gap-3 text-sm md:grid-cols-3">
                <div>
                    <dt class="text-ink-soft">Nomor RM</dt>
                    <dd class="font-mono text-ink">{{ $patient->medical_record_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">Nama Pasien</dt>
                    <dd class="text-ink">{{ $patient->name }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">Cabang</dt>
                    <dd class="text-ink">{{ $patient->branch?->code ?? '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Rekam Medis Native" padding="" data-section="native">
            @if ($nativeRecords->isEmpty())
                <div class="px-4 py-6">
                    <x-ui.empty-state
                        title="Rekam Medis Native: Belum Ada"
                        description="Pasien ini belum memiliki rekam medis native. Arsip legacy (bila ada) tetap dapat dibaca di bawah."
                    />
                </div>
            @else
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr class="text-left text-ink-soft">
                            <th scope="col" class="px-4 py-3 font-medium">Tanggal Kunjungan</th>
                            <th scope="col" class="px-3 py-3 font-medium">Nomor Kunjungan</th>
                            <th scope="col" class="px-3 py-3 font-medium">Cabang</th>
                            <th scope="col" class="px-3 py-3 font-medium">Dokter</th>
                            <th scope="col" class="px-3 py-3 font-medium">Status</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($nativeRecords as $record)
                            <tr>
                                <td class="px-4 py-3 text-ink-soft">{{ $record->clinicVisit?->visit_date?->format('d/m/Y') ?? '—' }}</td>
                                <td class="px-3 py-3 font-mono text-ink">{{ $record->clinicVisit?->visit_number ?? '—' }}</td>
                                <td class="px-3 py-3 text-ink-soft">{{ $record->branch?->code ?? '—' }}</td>
                                <td class="px-3 py-3 text-ink-soft">{{ $record->doctor?->name ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    <x-ui.badge :tone="$statusTone[$record->status] ?? 'neutral'">{{ $statusLabels[$record->status] ?? $record->status }}</x-ui.badge>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($record->clinicVisit)
                                        <x-ui.button variant="secondary" size="sm" :href="route('rme.visits.medical-record.show', $record->clinicVisit)">Ruang Kerja RM</x-ui.button>
                                    @else
                                        <span class="text-ink-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        <x-ui.card title="Arsip Legacy" padding="" data-section="legacy">
            @if ($legacyRme->isEmpty() && $legacyOdontogram->isEmpty())
                <div class="px-4 py-6">
                    <x-ui.empty-state
                        title="Tidak ada arsip legacy yang dapat Anda akses."
                        description="Hanya arsip yang sudah dipublikasi dan berada dalam cakupan akses Anda yang ditampilkan."
                    />
                </div>
            @else
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr class="text-left text-ink-soft">
                            <th scope="col" class="px-4 py-3 font-medium">Jenis</th>
                            <th scope="col" class="px-3 py-3 font-medium">Tanggal Dokumen</th>
                            <th scope="col" class="px-3 py-3 font-medium">Judul</th>
                            <th scope="col" class="px-3 py-3 font-medium">Halaman</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($legacyRme as $record)
                            <tr data-legacy-rme="{{ $record->id }}">
                                <td class="px-4 py-3"><x-ui.badge tone="success">Legacy RME</x-ui.badge></td>
                                <td class="px-3 py-3 text-ink-soft">
                                    {{ $record->rme_date?->format('d/m/Y') ?? '—' }}
                                    @if ($record->latest_rme_date && $record->rme_date && $record->latest_rme_date->gt($record->rme_date))
                                        – {{ $record->latest_rme_date->format('d/m/Y') }}
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-ink">{{ $record->title ?? 'Arsip RME Lama' }}</td>
                                <td class="px-3 py-3 text-ink-soft">{{ $record->page_count }}</td>
                                <td class="px-4 py-3 text-right">
                                    <x-ui.button variant="secondary" size="sm" :href="route('rme.legacy-records.show', $record->id)">Lihat Arsip</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                        @foreach ($legacyOdontogram as $record)
                            <tr data-legacy-odontogram="{{ $record->id }}">
                                <td class="px-4 py-3"><x-ui.badge tone="success">Legacy Odontogram</x-ui.badge></td>
                                <td class="px-3 py-3 text-ink-soft">{{ $record->odontogram_date?->format('d/m/Y') ?? '—' }}</td>
                                <td class="px-3 py-3 text-ink">{{ $record->title ?? 'Arsip Odontogram Lama' }}</td>
                                <td class="px-3 py-3 text-ink-soft">{{ $record->page_count }}</td>
                                <td class="px-4 py-3 text-right">
                                    <x-ui.button variant="secondary" size="sm" :href="route('rme.legacy-odontograms.show', $record->id)">Lihat Arsip</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
