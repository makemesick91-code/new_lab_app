{{--
    LEGACY-RME-PDF-1B — upload one historical RME document.

    The operator must pick the patient FIRST, because the valid date range
    depends on that patient's earliest native RME date. The date input's `max`
    is a browser convenience only — every bound is re-evaluated server-side by
    the 1A LegacyRmeDateRuleService.

    Patient search is Nomor RM only (canonical cross-branch lookup). KTP/NIK is
    never searched and never displayed.
--}}
<x-settings-shell title="Unggah Arsip RME Lama">
    <div class="space-y-6">
        <x-ui.page-header
            title="Unggah Arsip RME Lama"
            subtitle="Pilih pasien, tentukan tanggal yang tertera pada dokumen, lalu unggah PDF arsip."
        >
            <x-slot:breadcrumb>Master Data RME / Impor Arsip RME Lama / Unggah</x-slot:breadcrumb>

            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('settings.rme.legacy-imports.index')">Kembali</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        @if ($errors->any())
            <x-ui.alert variant="danger" title="Unggahan belum dapat diproses">
                <ul class="mt-1 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        {{-- Step 1 — patient --}}
        <x-ui.card title="1. Pilih Pasien" description="Pencarian menggunakan Nomor RM.">
            <form method="GET" action="{{ route('settings.rme.legacy-imports.create') }}" class="flex flex-col gap-3 md:flex-row md:items-end">
                <x-ui.input
                    name="rm"
                    label="Nomor RM"
                    :value="$rm"
                    placeholder="Contoh: DG-TKM1-2024-0001"
                    class="md:w-96"
                />
                <div class="flex gap-2">
                    <x-ui.button type="submit">Cari Pasien</x-ui.button>
                </div>
            </form>

            @if ($lookup !== null)
                <div class="mt-5">
                    @if (($lookup['too_short'] ?? false))
                        <x-ui.alert variant="warning">Masukkan minimal {{ $lookup['min_length'] ?? 4 }} karakter Nomor RM.</x-ui.alert>
                    @elseif (($lookup['too_many'] ?? false))
                        <x-ui.alert variant="warning">Terlalu banyak hasil. Lengkapi Nomor RM agar lebih spesifik.</x-ui.alert>
                    @elseif (empty($lookup['results']))
                        <x-ui.alert variant="warning">Pasien dengan Nomor RM tersebut tidak ditemukan.</x-ui.alert>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-hairline text-sm">
                                <thead class="bg-navy-50 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">
                                    <tr>
                                        <th class="px-4 py-3">Nama</th>
                                        <th class="px-4 py-3">Nomor RM</th>
                                        <th class="px-4 py-3">Cabang</th>
                                        <th class="px-4 py-3 text-right">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-hairline">
                                    @foreach ($lookup['results'] as $result)
                                        <tr>
                                            <td class="px-4 py-3 font-semibold text-navy">{{ $result['name'] ?? '—' }}</td>
                                            <td class="px-4 py-3 text-ink-soft">{{ $result['medical_record_number'] ?? '—' }}</td>
                                            <td class="px-4 py-3 text-ink-soft">{{ $result['branch_label'] ?? '—' }}</td>
                                            <td class="px-4 py-3 text-right">
                                                @if (! empty($result['id']))
                                                    <x-ui.button
                                                        size="sm"
                                                        :href="route('settings.rme.legacy-imports.create', ['patient_id' => $result['id'], 'rm' => $rm])"
                                                    >Pilih</x-ui.button>
                                                @else
                                                    <span class="text-xs text-ink-muted">Nomor RM ganda — pilih melalui Master Pasien</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        </x-ui.card>

        {{-- Step 2 — date + document --}}
        @if ($patient !== null && $summary !== null)
            <x-ui.card title="2. Tanggal & Dokumen" description="Tanggal wajib sama dengan yang tertulis pada PDF.">
                <dl class="mb-5 grid gap-4 rounded-lg bg-navy-50 p-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">Pasien</dt>
                        <dd class="mt-1 font-semibold text-navy">{{ $summary['name'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">Nomor RM</dt>
                        <dd class="mt-1 text-ink">{{ $summary['medical_record_number'] ?? 'Belum ada RM' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">Tanggal Lahir</dt>
                        <dd class="mt-1 text-ink">{{ $summary['date_of_birth'] ?? 'Tidak tercatat' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">RME Pertama di Sistem</dt>
                        <dd class="mt-1 font-semibold text-brand-700">{{ $summary['earliest_native_rme_date'] ?? 'Belum ada' }}</dd>
                    </div>
                </dl>

                @unless ($summary['has_native_rme'])
                    <x-ui.alert variant="danger" title="Pasien belum memiliki RME di sistem">
                        Tidak ada tanggal RME pembanding, sehingga arsip lama belum dapat diimpor untuk pasien ini.
                    </x-ui.alert>
                @else
                    <x-ui.alert variant="warning" title="Tanggal ditentukan manual">
                        Tanggal arsip harus <strong>lebih awal</strong> dari
                        {{ $summary['earliest_native_rme_date'] }} dan bukan hari ini. Sistem tidak membaca tanggal
                        dari isi PDF — masukkan sesuai yang tertulis pada dokumen.
                    </x-ui.alert>

                    <form
                        method="POST"
                        action="{{ route('settings.rme.legacy-imports.store') }}"
                        enctype="multipart/form-data"
                        class="mt-5 space-y-5"
                    >
                        @csrf
                        <input type="hidden" name="patient_id" value="{{ $patient->getKey() }}">

                        <div class="grid gap-4 md:grid-cols-2">
                            <x-ui.input
                                type="date"
                                name="selected_rme_date"
                                label="Tanggal RME Lama"
                                :value="old('selected_rme_date')"
                                :max="$maxSelectableDate"
                                required
                                help="Sesuai tanggal yang tertulis pada dokumen."
                            />

                            <x-ui.select name="origin_branch_id" label="Cabang Asal" help="Opsional — hanya catatan asal arsip.">
                                <option value="">Tidak dicatat</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected(old('origin_branch_id') == $branch->id)>{{ $branch->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <div>
                            <label for="document" class="block text-sm font-medium text-navy">Dokumen PDF <span class="text-danger">*</span></label>
                            <input
                                id="document"
                                type="file"
                                name="document"
                                accept="application/pdf,.pdf"
                                required
                                class="mt-1 block w-full rounded-lg border border-hairline text-sm text-ink file:mr-4 file:rounded-md file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100"
                            >
                            <p class="mt-1 text-xs text-ink-muted">Hanya PDF. PDF terenkripsi atau terkunci kata sandi akan ditolak.</p>
                            @error('document')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                        </div>

                        <div class="space-y-3 rounded-lg border border-hairline bg-surface p-4">
                            <label class="flex items-start gap-3 text-sm text-ink">
                                <input type="checkbox" name="patient_confirmation" value="1" class="mt-0.5 rounded border-hairline text-brand-600 focus:ring-brand-500" required>
                                <span>Saya memastikan PDF ini milik pasien yang dipilih.</span>
                            </label>
                            <label class="flex items-start gap-3 text-sm text-ink">
                                <input type="checkbox" name="date_confirmation" value="1" class="mt-0.5 rounded border-hairline text-brand-600 focus:ring-brand-500" required>
                                <span>Saya memastikan tanggal yang dipilih sama dengan tanggal RME yang tertulis pada PDF.</span>
                            </label>
                        </div>

                        <div class="flex justify-end gap-2">
                            <x-ui.button variant="secondary" :href="route('settings.rme.legacy-imports.index')">Batal</x-ui.button>
                            <x-ui.button type="submit">Unggah &amp; Proses</x-ui.button>
                        </div>
                    </form>
                @endunless
            </x-ui.card>
        @endif
    </div>
</x-settings-shell>
