{{-- SATUSEHAT-4A/4B — Master clinical diagnosis governance + review lifecycle.
     Master ≠ SATUSEHAT mapping: mapping tetap lifecycle review terpisah.
     Lifecycle: draft → under_review → approved → active → deprecated/rejected.
     Review actions butuh review_clinical_terminology; self-approval ditolak
     server-side (pemisahan tugas). --}}
<x-settings-shell title="Master Diagnosis Klinis">
    <x-ui.page-header
        title="Master & Review Terminologi Diagnosis"
        subtitle="Referensi diagnosis terstruktur (default ICD-10). Entri baru masuk sebagai DRAFT dan hanya dapat dipilih dokter setelah AKTIF melalui review klinis. Entri master TIDAK otomatis siap SATUSEHAT — mapping Condition tetap butuh review terpisah.">
        <x-slot:breadcrumb>SATUSEHAT</x-slot:breadcrumb>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif
    @if ($errors->any())
        <x-ui.alert variant="danger" title="Validasi gagal">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        @can('manage_structured_diagnoses')
            <x-ui.card title="Tambah Diagnosis Master (DRAFT)">
                <form method="POST" action="{{ route('satusehat.diagnoses.store') }}" class="space-y-3">
                    @csrf
                    <x-ui.input label="Code System" name="code_system" value="ICD-10" />
                    <x-ui.input label="Kode" name="code" required />
                    <x-ui.input label="Nama (display)" name="display" required />
                    <x-ui.input label="Sumber resmi" name="source" value="WHO ICD-10" />
                    <x-ui.input label="Versi sumber" name="source_version" placeholder="mis. ICD-10 2019" />
                    <x-ui.input label="Alias pencarian (opsional)" name="aliases" placeholder="mis. gigi berlubang" />
                    <x-ui.button type="submit" class="w-full">Simpan sebagai Draft</x-ui.button>
                </form>
                <p class="mt-3 text-xs text-ink-muted">
                    Aktivasi membutuhkan sumber resmi + persetujuan reviewer klinis yang berbeda dari pembuat/pengaju.
                </p>
            </x-ui.card>
        @endcan

        <x-ui.card title="Daftar Terminologi" class="lg:col-span-2">
            <x-ui.filter-bar :action="route('satusehat.diagnoses.index')" method="GET">
                <x-ui.input label="Cari (kode / nama)" name="search" :value="$filters['search'] ?? null" />
                <x-ui.select label="Status" name="status">
                    <option value="">Semua status</option>
                    @foreach (['draft', 'under_review', 'approved', 'rejected', 'active', 'deprecated'] as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                    @endforeach
                </x-ui.select>
                <x-slot:actions>
                    <x-ui.button type="submit">Cari</x-ui.button>
                </x-slot:actions>
            </x-ui.filter-bar>

            <div class="mt-3 overflow-x-auto">
                <x-ui.table>
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left">Kode</th>
                            <th class="px-3 py-2 text-left">Nama</th>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-left">Dipakai</th>
                            <th class="px-3 py-2 text-left">Pengganti</th>
                            <th class="px-3 py-2 text-left">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($diagnoses as $dx)
                            <tr class="border-t border-hairline align-top">
                                <td class="px-3 py-2 font-mono text-sm">{{ $dx->code_system }} {{ $dx->code }}</td>
                                <td class="px-3 py-2">
                                    {{ $dx->display }}
                                    @if ($dx->source)
                                        <p class="text-xs text-ink-muted">{{ $dx->source }}{{ $dx->source_version ? ' — '.$dx->source_version : '' }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <x-ui.badge :tone="$dx->isActive() ? 'success' : ($dx->status === 'under_review' ? 'info' : ($dx->status === 'approved' ? 'info' : 'neutral'))">{{ $dx->status }}</x-ui.badge>
                                </td>
                                <td class="px-3 py-2 text-sm">{{ $dx->records_count ?? 0 }} RM</td>
                                <td class="px-3 py-2 text-sm">
                                    {{ $dx->replacement ? $dx->replacement->code.' — '.$dx->replacement->display : '-' }}
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-col gap-2">
                                        @can('manage_structured_diagnoses')
                                            @if (in_array($dx->status, ['draft', 'rejected'], true))
                                                <form method="POST" action="{{ route('satusehat.diagnoses.submit-review', $dx) }}">
                                                    @csrf
                                                    <x-ui.button size="sm" type="submit" variant="secondary">Ajukan Review</x-ui.button>
                                                </form>
                                            @endif
                                        @endcan
                                        @can('review_clinical_terminology')
                                            @if ($dx->status === 'under_review')
                                                <form method="POST" action="{{ route('satusehat.diagnoses.approve', $dx) }}" class="flex items-end gap-2">
                                                    @csrf
                                                    <x-ui.input label="Alasan" name="reason" required />
                                                    <x-ui.button size="sm" type="submit" variant="success">Setujui</x-ui.button>
                                                </form>
                                                <form method="POST" action="{{ route('satusehat.diagnoses.reject', $dx) }}" class="flex items-end gap-2">
                                                    @csrf
                                                    <x-ui.input label="Alasan" name="reason" required />
                                                    <x-ui.button size="sm" type="submit" variant="danger">Tolak</x-ui.button>
                                                </form>
                                            @endif
                                            @if ($dx->status === 'approved')
                                                <form method="POST" action="{{ route('satusehat.diagnoses.activate', $dx) }}">
                                                    @csrf
                                                    <x-ui.button size="sm" type="submit">Aktifkan</x-ui.button>
                                                </form>
                                            @endif
                                            @if ($dx->isActive())
                                                <form method="POST" action="{{ route('satusehat.diagnoses.deprecate', $dx) }}" class="flex items-end gap-2">
                                                    @csrf
                                                    <x-ui.select label="Pengganti (opsional)" name="replacement_diagnosis_id">
                                                        <option value="">- tanpa pengganti -</option>
                                                        @foreach ($activeReplacements as $candidate)
                                                            @continue($candidate->id === $dx->id)
                                                            <option value="{{ $candidate->id }}">{{ $candidate->code }} — {{ $candidate->display }}</option>
                                                        @endforeach
                                                    </x-ui.select>
                                                    <x-ui.button size="sm" type="submit" variant="warning">Nonaktifkan</x-ui.button>
                                                </form>
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-3 py-6">
                                    <x-ui.empty-state title="Belum ada diagnosis master" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            </div>
            <div class="mt-3">{{ $diagnoses->links() }}</div>
        </x-ui.card>
    </div>
</x-settings-shell>
