{{-- SATUSEHAT-1 — Controlled submission filter/review. NIK is never shown in
     full. There is no "select all across all pages" and no "send all". --}}
<x-settings-shell title="SATUSEHAT — Filter Pengiriman">
    <x-ui.page-header
        title="Filter Pengiriman SATUSEHAT"
        subtitle="Setiap kunjungan hanya menjadi kandidat. Tidak ada pengiriman otomatis — persetujuan eksplisit diperlukan.">
        <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
    </x-ui.page-header>

    <x-ui.alert variant="{{ $integrationEnabled ? 'warning' : 'info' }}" title="Integrasi SATUSEHAT">
        Lingkungan: <strong>{{ $environment }}</strong>.
        Pengiriman eksternal <strong>{{ $integrationEnabled ? 'AKTIF' : 'NONAKTIF' }}</strong> —
        pada SATUSEHAT-1 data belum dikirim dan belum diverifikasi oleh API SATUSEHAT.
    </x-ui.alert>

    <x-ui.filter-bar :action="route('satusehat.submissions.index')" method="GET">
        <x-ui.input label="Cari (nama / no. RM)" name="search" :value="$filters['search']" />
        <x-ui.input label="Tanggal dari" name="visit_date_from" type="date" :value="$filters['visit_date_from']" />
        <x-ui.input label="Tanggal sampai" name="visit_date_to" type="date" :value="$filters['visit_date_to']" />
        <x-ui.select label="Cabang RME" name="branch_id">
            <option value="">Semua cabang</option>
            @foreach ($rmeBranches as $branch)
                <option value="{{ $branch->id }}" @selected($filters['branch_id'] == $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select label="Dokter" name="doctor_id">
            <option value="">Semua dokter</option>
            @foreach ($doctors as $doctor)
                <option value="{{ $doctor->id }}" @selected($filters['doctor_id'] == $doctor->id)>{{ $doctor->name }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select label="Readiness" name="readiness_status">
            <option value="">Semua</option>
            @foreach ($readinessStatuses as $status)
                <option value="{{ $status }}" @selected($filters['readiness_status'] === $status)>{{ $status }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select label="Status Review" name="review_status">
            <option value="">Semua</option>
            @foreach ($reviewStatuses as $status)
                <option value="{{ $status }}" @selected($filters['review_status'] === $status)>{{ $status }}</option>
            @endforeach
        </x-ui.select>
        <x-slot:actions>
            <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
            <x-ui.button variant="secondary" :href="route('satusehat.submissions.index')">Atur Ulang</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif
    @if ($errors->any())
        <x-ui.alert variant="danger">{{ $errors->first() }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('satusehat.submissions.bulk') }}">
        @csrf
        <x-ui.card padding="">
            @if ($candidates->isEmpty())
                <x-ui.empty-state title="Belum ada kandidat SATUSEHAT."
                    description="Kandidat dibuat otomatis saat kunjungan selesai dengan rekam medis final, atau lewat backfill." />
            @else
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <thead class="bg-navy-50">
                            <tr class="text-left text-ink-soft">
                                <th class="px-4 py-3"></th>
                                <th class="px-4 py-3">Tgl Kunjungan</th>
                                <th class="px-4 py-3">Cabang</th>
                                <th class="px-4 py-3">No. RM</th>
                                <th class="px-4 py-3">Pasien</th>
                                <th class="px-4 py-3">Dokter</th>
                                <th class="px-4 py-3">Readiness</th>
                                <th class="px-4 py-3">Review</th>
                                <th class="px-4 py-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($candidates as $candidate)
                                <tr>
                                    <td class="px-4 py-3">
                                        <input type="checkbox" name="candidate_ids[]" value="{{ $candidate->id }}" class="rounded border-hairline">
                                    </td>
                                    <td class="px-4 py-3">{{ optional($candidate->clinicVisit?->visit_date)->format('d M Y') }}</td>
                                    <td class="px-4 py-3">{{ $candidate->branch?->name }}</td>
                                    <td class="px-4 py-3">{{ $candidate->patient?->medical_record_number }}</td>
                                    <td class="px-4 py-3">{{ $candidate->patient?->name }}</td>
                                    <td class="px-4 py-3">{{ $candidate->doctor?->name ?? '—' }}</td>
                                    <td class="px-4 py-3"><x-ui.badge :tone="$candidate->readinessTone()">{{ $candidate->readinessLabel() }}</x-ui.badge></td>
                                    <td class="px-4 py-3"><x-ui.badge :tone="$candidate->reviewTone()">{{ $candidate->reviewLabel() }}</x-ui.badge></td>
                                    <td class="px-4 py-3">
                                        <x-ui.button size="sm" variant="secondary" :href="route('satusehat.submissions.show', $candidate)">Detail</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                </div>

                @canany(['review_satusehat_submissions', 'send_satusehat_submissions'])
                    <div class="flex flex-wrap items-end gap-3 border-t border-hairline p-4">
                        <x-ui.select label="Aksi massal" name="action">
                            @can('review_satusehat_submissions')
                                <option value="approve">Setujui terpilih</option>
                                <option value="exclude">Kecualikan terpilih</option>
                            @endcan
                            @can('send_satusehat_submissions')
                                <option value="prepare">Siapkan untuk SATUSEHAT-2 (tidak dikirim)</option>
                            @endcan
                        </x-ui.select>
                        <x-ui.input label="Alasan (untuk exclude)" name="exclusion_reason" />
                        <x-ui.button type="submit" variant="primary">Jalankan (kandidat terpilih)</x-ui.button>
                    </div>
                    <p class="px-4 pb-4 text-xs text-ink-muted">
                        Hanya kandidat yang dicentang pada halaman ini yang diproses. Tidak ada "pilih semua halaman" atau "kirim semua".
                        Kelayakan &amp; otorisasi dihitung ulang di server.
                    </p>
                @endcanany
            @endif
        </x-ui.card>
    </form>

    <div class="mt-4">{{ $candidates->links() }}</div>
</x-settings-shell>
