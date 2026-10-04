{{--
    FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — Kelengkapan Arsip Pasien Legacy.

    PRESENTATION ONLY. Every state, verdict, count and branch on this page was
    derived server-side in LegacyPatientArchiveCompletenessService from the
    actor's own authorized branch scope. There is no completeness rule, no
    branch arithmetic and no status derivation in this template.

    READ-ONLY. This page contains no form that mutates anything: the only form
    is the GET filter bar. It cannot create a patient or an import, and it
    cannot review, publish, void or cancel one.

    PII BOUNDARY. The row objects expose the Nomor RM, the patient name, the
    branch, the two document states and the verdict — nothing else. No KTP/NIK,
    no address, no phone or WhatsApp number, no e-mail, no date of birth, no
    clinical content, no document title, no storage path and no checksum.
--}}
@php
    use App\Modules\LegacyImport\Completeness\Support\LegacyArchiveStatusLabel;
@endphp

<x-settings-shell title="Kelengkapan Arsip Pasien Legacy">
    <div class="space-y-6">
        <x-ui.page-header
            title="Kelengkapan Arsip Pasien Legacy"
            subtitle="Pasien hasil impor legacy yang arsip RME dan/atau odontogram lamanya belum lengkap."
        >
            <x-slot:breadcrumb>Import Data Legacy / Kelengkapan Arsip Pasien Legacy</x-slot:breadcrumb>
        </x-ui.page-header>

        <x-ui.alert variant="info" title="Halaman pemantauan, bukan halaman kerja">
            Daftar ini <strong>hanya menampilkan pasien hasil impor legacy</strong>. Pasien yang didaftarkan
            melalui alur pendaftaran normal aplikasi tidak pernah muncul di sini. Halaman ini bersifat
            <strong>baca saja</strong> — tidak membuat, meninjau, menerbitkan, maupun membatalkan dokumen apa pun.
        </x-ui.alert>

        @if ($scopeIsEmpty)
            <x-ui.alert variant="warning" title="Cabang belum dapat ditentukan">
                Akun Anda belum terhubung ke cabang RME aktif mana pun, sehingga tidak ada baris yang dapat
                ditampilkan. Hubungi admin untuk menetapkan cabang. Ini bukan tanda data hilang — pembatasan
                cabang diputuskan di sisi server dan gagal tertutup bila cabang tidak dapat ditentukan.
            </x-ui.alert>
        @endif

        {{-- Ringkasan: selalu mengikuti cakupan cabang aktor, bukan angka global. --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <x-ui.kpi-card label="Total Pasien Legacy" :value="number_format($summary['total'])" />
            <x-ui.kpi-card label="Lengkap" :value="number_format($summary['complete'])" />
            <x-ui.kpi-card label="Belum Lengkap" :value="number_format($summary['incomplete'])" />
            <x-ui.kpi-card label="Belum Ada RME" :value="number_format($summary['missing_rme'])" />
            <x-ui.kpi-card label="Belum Ada Odontogram" :value="number_format($summary['missing_odontogram'])" />
            <x-ui.kpi-card label="Dalam Proses" :value="number_format($summary['in_progress'])" />
        </div>

        <x-ui.filter-bar :action="route('settings.legacy-patient-completeness.index')">
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <x-ui.select name="status" label="Status Kelengkapan">
                    @foreach ($filterOptions as $value => $label)
                        <option value="{{ $value }}" @selected($activeFilter === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.select>

                @if ($governsEveryBranch && $branchOptions !== [])
                    <x-ui.select name="branch_id" label="Cabang">
                        <option value="">Semua cabang dalam cakupan</option>
                        @foreach ($branchOptions as $branchId => $branchLabel)
                            <option value="{{ $branchId }}" @selected($activeBranchId === $branchId)>{{ $branchLabel }}</option>
                        @endforeach
                    </x-ui.select>
                @endif

                <x-ui.input
                    name="q"
                    label="Cari Nomor RM atau Nama"
                    :value="$activeSearch"
                    placeholder="Mis. DG-SPN4-2024-0001"
                    help="Nomor RM adalah pencarian utama."
                />

                <div class="flex items-end gap-2">
                    <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
                    <x-ui.button
                        type="button"
                        variant="secondary"
                        onclick="window.location='{{ route('settings.legacy-patient-completeness.index') }}'"
                    >Atur Ulang</x-ui.button>
                </div>
            </div>
        </x-ui.filter-bar>

        <x-ui.card padding="p-0">
            @if ($rows->total() === 0)
                <div class="p-6">
                    <x-ui.empty-state
                        title="Tidak ada pasien legacy pada filter ini"
                        description="Coba ganti filter status, cabang, atau kata kunci pencarian."
                    />
                </div>
            @else
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <thead class="bg-navy-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Nomor RM</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Nama Pasien</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Cabang</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Status Legacy RME</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Status Legacy Odontogram</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Status Kelengkapan</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Batch</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($rows as $row)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-navy">{{ $row->medicalRecordLabel() }}</td>
                                    <td class="px-4 py-3 text-ink">{{ $row->name }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-ink-soft">{{ $row->branchLabel() }}</td>

                                    <td class="whitespace-nowrap px-4 py-3">
                                        <x-ui.badge :tone="LegacyArchiveStatusLabel::documentTone($row->rmeState, $row->rmeReviewBlocked)">
                                            {{ LegacyArchiveStatusLabel::document($row->rmeState, $row->rmeRawStatus, $row->rmeReviewBlocked) }}
                                        </x-ui.badge>
                                        @if ($row->rmeArchiveDate !== null)
                                            <div class="mt-1 text-xs text-ink-muted">Arsip: {{ $row->rmeArchiveDate }}</div>
                                        @endif
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3">
                                        <x-ui.badge :tone="LegacyArchiveStatusLabel::documentTone($row->odontogramState, $row->odontogramReviewBlocked)">
                                            {{ LegacyArchiveStatusLabel::document($row->odontogramState, $row->odontogramRawStatus, $row->odontogramReviewBlocked) }}
                                        </x-ui.badge>
                                        @if ($row->odontogramArchiveDate !== null)
                                            <div class="mt-1 text-xs text-ink-muted">Arsip: {{ $row->odontogramArchiveDate }}</div>
                                        @endif
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3">
                                        <x-ui.badge :tone="LegacyArchiveStatusLabel::completenessTone($row->completeness)">
                                            {{ LegacyArchiveStatusLabel::completeness($row->completeness) }}
                                        </x-ui.badge>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-xs text-ink-muted">
                                        {{ $row->importBatchId === null ? '—' : '#'.$row->importBatchId }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                </div>

                <div class="border-t border-hairline p-4">
                    {{ $rows->links() }}
                </div>
            @endif
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-2 text-sm text-ink-soft">
                <p class="font-semibold text-navy">Cara membaca status</p>
                <p>
                    <strong>Belum Ada</strong> — belum ada arsip sama sekali, slot pasien kosong dan dapat diunggah.
                    <strong>Dalam Proses</strong> / <strong>Siap Ditinjau</strong> / <strong>Ditinjau</strong> /
                    <strong>Gagal — Dapat Diulang</strong> / <strong>Ditahan Peninjau</strong> — sudah ada
                    proses berjalan yang <em>masih memegang slot pasien</em>, sehingga unggahan baru akan ditolak
                    server. Lanjutkan atau batalkan proses yang ada, jangan mengunggah ulang.
                </p>
                <p>
                    <strong>Published</strong> — arsip sudah terbit dan terhitung lengkap.
                    <strong>Void / Perlu Koreksi</strong> — arsip pernah terbit lalu ditarik; slot kembali kosong
                    sehingga koreksi dilakukan dengan impor baru.
                </p>
                <p class="text-xs text-ink-muted">
                    Arsip RME dan arsip odontogram dinilai terpisah — satu arsip yang sudah terbit tidak pernah
                    menggantikan arsip jenis lain yang belum ada.
                </p>
            </div>
        </x-ui.card>
    </div>
</x-settings-shell>
