{{--
    FIX-04b — upload one scanned historical odontogram chart.

    NOTE WHAT THIS FORM DOES NOT ASK FOR: a branch. The owning branch is derived
    server-side from the patient's Nomor RM and is shown here read-only, so an
    operator can see where the archive will land without being able to change it.

    KTP/NIK is never rendered.
--}}
<x-settings-shell title="Unggah Arsip Odontogram Lama">
    <div class="space-y-6">
        <x-ui.page-header
            title="Unggah Arsip Odontogram Lama"
            subtitle="Pilih pasien, tentukan tanggal sesuai yang tertera pada dokumen, lalu unggah hasil pindai odontogram lama dalam format PDF."
        >
            <x-slot:breadcrumb>Master Data RME / Impor Arsip Odontogram Lama / Unggah</x-slot:breadcrumb>

            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('settings.rme.legacy-odontograms.index')">Kembali</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.alert variant="warning" title="Dokumen ini menjadi bukti klinis permanen">
            Setelah dipublikasikan, arsip tidak dapat diubah atau dihapus. Koreksi dilakukan dengan membatalkan
            (VOID) arsip disertai alasan, lalu mengunggah ulang dokumen yang benar.
        </x-ui.alert>

        <form method="GET" action="{{ route('settings.rme.legacy-odontograms.create') }}">
            <x-ui.card title="1. Pilih Pasien">
                <div class="flex flex-wrap items-end gap-3">
                    <x-ui.input
                        name="patient_id"
                        type="number"
                        label="ID Pasien"
                        :value="$patient?->getKey()"
                        placeholder="Masukkan ID pasien"
                    />
                    <x-ui.button type="submit" variant="secondary">Muat Data Pasien</x-ui.button>
                </div>
            </x-ui.card>
        </form>

        @if ($patient !== null)
            <x-ui.card title="2. Konteks Pasien (ditentukan sistem)">
                <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">Pasien</dt>
                        <dd class="mt-1 font-semibold text-navy">{{ $patient->name }}</dd>
                        <dd class="text-xs text-ink-muted">{{ $patient->medical_record_number ?? 'Belum ada RM' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">Cabang Arsip</dt>
                        @if ($branchResolution !== null && $branchResolution->resolved)
                            <dd class="mt-1 font-semibold text-navy">{{ $branchResolution->branchName }}</dd>
                            <dd class="text-xs text-ink-muted">
                                Diturunkan dari Nomor RM ({{ $branchResolution->branchCode }}) dan tidak dapat diubah manual.
                            </dd>
                        @else
                            <dd class="mt-1 text-danger-700">Tidak dapat ditentukan</dd>
                            <dd class="text-xs text-danger-700">{{ $branchResolution?->message }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">Odontogram Pertama di Sistem</dt>
                        @if ($earliestNativeOdontogramDate !== null)
                            <dd class="mt-1 font-semibold text-navy">
                                {{ \Illuminate\Support\Carbon::parse($earliestNativeOdontogramDate)->format('d-m-Y') }}
                            </dd>
                            <dd class="text-xs text-ink-muted">Tanggal arsip harus lebih awal dari tanggal ini.</dd>
                        @else
                            <dd class="mt-1 text-warning-700">Belum ada</dd>
                            <dd class="text-xs text-warning-700">
                                Pasien belum memiliki odontogram di sistem, sehingga arsip lama belum dapat diarsipkan.
                            </dd>
                        @endif
                    </div>
                </dl>
            </x-ui.card>

            <form
                method="POST"
                action="{{ route('settings.rme.legacy-odontograms.store') }}"
                enctype="multipart/form-data"
            >
                @csrf
                <input type="hidden" name="patient_id" value="{{ $patient->getKey() }}">

                <x-ui.card title="3. Dokumen &amp; Tanggal">
                    <div class="space-y-4">
                        <x-ui.input
                            name="selected_odontogram_date"
                            type="date"
                            label="Tanggal Odontogram Lama (sesuai dokumen)"
                            :value="old('selected_odontogram_date')"
                            required
                        />
                        <p class="text-xs text-ink-muted">
                            Tanggal dibaca manual dari dokumen — bukan tanggal unggah, bukan tanggal berkas, dan bukan
                            metadata PDF.
                        </p>

                        <div>
                            <label for="document" class="mb-1 block text-sm font-medium text-navy">Berkas PDF</label>
                            <input
                                id="document"
                                name="document"
                                type="file"
                                accept="application/pdf"
                                required
                                class="block w-full rounded-xl border border-hairline bg-surface px-3 py-2 text-sm text-ink focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100"
                            >
                            @error('document')
                                <p class="mt-1 text-xs text-danger-700">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-ink-muted">Hanya PDF. Format lain ditolak oleh sistem.</p>
                        </div>

                        <label class="flex items-start gap-2 text-sm text-ink">
                            <input type="checkbox" name="patient_confirmation" value="1" class="mt-1 rounded border-hairline text-brand-600 focus:ring-brand-100">
                            <span>Saya memastikan dokumen ini milik pasien di atas.</span>
                        </label>
                        @error('patient_confirmation')
                            <p class="text-xs text-danger-700">{{ $message }}</p>
                        @enderror

                        <label class="flex items-start gap-2 text-sm text-ink">
                            <input type="checkbox" name="date_confirmation" value="1" class="mt-1 rounded border-hairline text-brand-600 focus:ring-brand-100">
                            <span>Saya memastikan tanggal di atas sesuai dengan yang tertera pada dokumen.</span>
                        </label>
                        @error('date_confirmation')
                            <p class="text-xs text-danger-700">{{ $message }}</p>
                        @enderror

                        @error('selected_odontogram_date')
                            <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
                        @enderror
                        @error('patient_id')
                            <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
                        @enderror
                        @error('legacy_odontogram')
                            <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
                        @enderror
                    </div>

                    <x-slot:actions>
                        <x-ui.button type="submit">Unggah &amp; Proses</x-ui.button>
                    </x-slot:actions>
                </x-ui.card>
            </form>
        @else
            <x-ui.empty-state
                title="Belum ada pasien dipilih"
                description="Masukkan ID pasien terlebih dahulu untuk melihat cabang arsip dan batas tanggal yang berlaku."
            />
        @endif
    </div>
</x-settings-shell>
