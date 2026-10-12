@php
    use App\Modules\ClinicOversight\Services\OversightMonitoringService;
@endphp
<x-settings-shell title="Riwayat RME Pasien">
    <x-ui.page-header :title="$patient->name" subtitle="Riwayat RME pasien — kunjungan native, buku RM tulisan tangan, dan arsip lama lintas cabang RME.">
        <x-slot:breadcrumb>Finance &amp; Data Analyst · Rekam Medis Elektronik · Riwayat RME Pasien</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" :href="route('oversight.rme.index')">&larr; Semua RME</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <x-ui.card title="Identitas Pasien" padding="p-5">
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-4">
            <div><dt class="text-xs text-ink-soft">No. Rekam Medis</dt><dd class="font-mono text-navy">{{ $patient->medical_record_number }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Nama</dt><dd class="text-navy">{{ $patient->name }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Telepon / WhatsApp</dt><dd class="text-navy">{{ $patient->phone ?: ($patient->whatsapp_number ?: '-') }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Cabang Pasien</dt><dd class="text-navy">{{ $patient->branch?->name ?? '-' }}</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.card title="Kunjungan (RME Native)" :description="'Maksimal 200 kunjungan terbaru ditampilkan. Total: '.$visits->count()">
        @if ($visits->isEmpty())
            <x-ui.empty-state title="Belum ada kunjungan native" description="Pasien ini hanya memiliki arsip lama pada cabang yang dapat Anda lihat." />
        @else
            <x-ui.table>
                <thead class="bg-canvas text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th class="px-3 py-2">Tanggal</th>
                        <th class="px-3 py-2">No. Kunjungan</th>
                        <th class="px-3 py-2">Cabang</th>
                        <th class="px-3 py-2">Dokter</th>
                        <th class="px-3 py-2">Ruangan</th>
                        <th class="px-3 py-2">Status Kunjungan</th>
                        <th class="px-3 py-2">Lembar RM</th>
                        <th class="px-3 py-2">Odontogram</th>
                        <th class="px-3 py-2">Resep</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($visits as $visit)
                        <tr>
                            <td class="px-3 py-2">{{ $visit->visit_date?->format('d-m-Y') }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $visit->visit_number }}</td>
                            <td class="px-3 py-2">{{ $visit->branch?->name ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $visit->doctor?->name ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $visit->clinicRoom?->name ?? '-' }}</td>
                            <td class="px-3 py-2"><x-ui.badge :status="$visit->status">{{ OversightMonitoringService::statusLabel($visit->status) }}</x-ui.badge></td>
                            <td class="px-3 py-2">{{ $visit->medicalRecord ? ($visit->medicalRecord->status === 'final' ? 'Final' : 'Draft') : '-' }}</td>
                            <td class="px-3 py-2">{{ $visit->odontogram ? 'Ada' : '-' }}</td>
                            <td class="px-3 py-2">{{ $visit->rmePrescription ? 'Ada' : '-' }}</td>
                            <td class="px-3 py-2 text-right"><x-ui.button variant="secondary" size="sm" :href="route('oversight.rme.visits.show', $visit->id)">Detail</x-ui.button></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card title="Buku RM Tulisan Tangan" description="Satu pasien = satu buku RM; halaman dikelompokkan menurut lembar kunjungan.">
        @if ($handwritingBook->isEmpty())
            <p class="text-sm text-ink-soft">Belum ada halaman tulisan tangan.</p>
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($handwritingBook as $page)
                    <figure class="rounded-lg border border-hairline p-2">
                        <figcaption class="mb-2 text-xs text-ink-soft">
                            Halaman {{ $page['position'] }} · Kunjungan {{ $page['visit_number'] ?? '-' }}
                            ({{ $page['visit_date']?->format('d-m-Y') ?? '-' }})
                        </figcaption>
                        <img loading="lazy" class="w-full rounded border border-hairline bg-white"
                             alt="Halaman RM {{ $page['position'] }}"
                             src="{{ $page['kind'] === 'handwriting'
                                ? route('oversight.rme.handwritings.image', $page['id'])
                                : route('oversight.rme.handwriting-pages.image', $page['id']) }}">
                    </figure>
                @endforeach
            </div>
        @endif
    </x-ui.card>

    <x-ui.card title="Arsip RME Lama (PUBLISHED)">
        @if ($legacyRme->isEmpty())
            <p class="text-sm text-ink-soft">Tidak ada arsip RME lama pada cabang yang dapat Anda lihat.</p>
        @else
            <x-ui.table>
                <thead class="bg-canvas text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr><th class="px-3 py-2">Tanggal Dokumen</th><th class="px-3 py-2">Judul</th><th class="px-3 py-2">Cabang Asal</th><th class="px-3 py-2 text-right">Halaman</th><th class="px-3 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($legacyRme as $record)
                        <tr>
                            <td class="px-3 py-2">{{ $record->rme_date?->format('d-m-Y') ?? '-' }}@if ($record->latest_rme_date && $record->latest_rme_date->gt($record->rme_date)) – {{ $record->latest_rme_date->format('d-m-Y') }}@endif</td>
                            <td class="px-3 py-2">{{ $record->title ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $record->originBranch?->name ?? '-' }}</td>
                            <td class="px-3 py-2 text-right">{{ $record->page_count }}</td>
                            <td class="px-3 py-2 text-right"><x-ui.button variant="secondary" size="sm" :href="route('oversight.legacy.rme.show', $record->id)">Lihat</x-ui.button></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card title="Arsip Odontogram Lama (PUBLISHED)">
        @if ($legacyOdontogram->isEmpty())
            <p class="text-sm text-ink-soft">Tidak ada arsip odontogram lama pada cabang yang dapat Anda lihat.</p>
        @else
            <x-ui.table>
                <thead class="bg-canvas text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr><th class="px-3 py-2">Tanggal Dokumen</th><th class="px-3 py-2">Judul</th><th class="px-3 py-2">Cabang</th><th class="px-3 py-2 text-right">Halaman</th><th class="px-3 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($legacyOdontogram as $record)
                        <tr>
                            <td class="px-3 py-2">{{ $record->odontogram_date?->format('d-m-Y') ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $record->title ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $record->branch?->name ?? '-' }}</td>
                            <td class="px-3 py-2 text-right">{{ $record->page_count }}</td>
                            <td class="px-3 py-2 text-right"><x-ui.button variant="secondary" size="sm" :href="route('oversight.legacy.odontogram.show', $record->id)">Lihat</x-ui.button></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</x-settings-shell>
