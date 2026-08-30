{{--
    FIX-04b — upload one scanned historical odontogram chart.

    NOTE WHAT THIS FORM DOES NOT ASK FOR: a branch. The owning branch is derived
    server-side from the patient's Nomor RM and is shown here read-only, so an
    operator can see where the archive will land without being able to change it.

    BUGFIX-LEGACY-ODONTOGRAM-PATIENT-LOOKUP-1 — step 1 asks for the Nomor RM,
    the identifier actually printed on the chart in the operator's hand. It used
    to ask for `mst_patients.id`, a number displayed nowhere in DaengtisiaMS, and
    every failure — an unknown patient, a deleted one, a database fault, or an
    operator typing the Nomor RM they had — rendered the SAME blank panel the
    page shows before any input at all. The lookup now reports its state, and
    each state has its own words.

    KTP/NIK is never rendered. The template receives an identity object carrying
    four fields, not a Patient model, so there is nothing sensitive here to leak.
--}}
<x-settings-shell title="Unggah Arsip Odontogram Lama">
    <div class="space-y-6">
        <x-ui.page-header
            title="Unggah Arsip Odontogram Lama"
            subtitle="Cari pasien dengan Nomor RM, tentukan tanggal sesuai yang tertera pada dokumen, lalu unggah hasil pindai odontogram lama dalam format PDF."
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

        <x-ui.card title="1. Pilih Pasien" description="Pencarian menggunakan Nomor RM.">
            <form
                method="GET"
                action="{{ route('settings.rme.legacy-odontograms.create') }}"
                class="flex flex-col gap-3 md:flex-row md:items-end"
            >
                <x-ui.input
                    name="rm"
                    label="Nomor RM Pasien"
                    :value="$submittedMedicalRecordNumber"
                    placeholder="Contoh: DG-TKM1-2024-0001"
                    class="md:w-96"
                />
                <div class="flex gap-2">
                    <x-ui.button type="submit">Cari Pasien</x-ui.button>
                </div>
            </form>

            {{-- One state, one message. Never a shared blank panel. --}}
            @if ($lookup->isError())
                <div class="mt-5">
                    <x-ui.alert variant="danger" title="Pencarian pasien gagal">{{ $lookup->message }}</x-ui.alert>
                </div>
            @elseif ($lookup->isAmbiguous())
                <div class="mt-5 space-y-3">
                    <x-ui.alert variant="warning">{{ $lookup->message }}</x-ui.alert>

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
                                @foreach ($lookup->candidates as $candidate)
                                    <tr>
                                        <td class="px-4 py-3 font-semibold text-navy">{{ $candidate->name }}</td>
                                        <td class="px-4 py-3 text-ink-soft">{{ $candidate->medicalRecordNumber ?? 'Belum ada RM' }}</td>
                                        <td class="px-4 py-3 text-ink-soft">{{ $candidate->branchLabel }}</td>
                                        <td class="px-4 py-3 text-right">
                                            <x-ui.button
                                                size="sm"
                                                :href="route('settings.rme.legacy-odontograms.create', [
                                                    'patient_id' => $candidate->id,
                                                    'rm' => $submittedMedicalRecordNumber,
                                                ])"
                                            >Pilih</x-ui.button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @elseif ($lookup->isFound())
                <div class="mt-5">
                    <x-ui.alert variant="success">{{ $lookup->message }}</x-ui.alert>
                </div>
            @elseif (! $lookup->isIdle())
                <div class="mt-5">
                    <x-ui.alert variant="warning">{{ $lookup->message }}</x-ui.alert>
                </div>
            @endif
        </x-ui.card>

        @if ($lookup->isFound())
            @php($patient = $lookup->identity)

            <x-ui.card title="2. Konteks Pasien (ditentukan sistem)">
                <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">Pasien</dt>
                        <dd class="mt-1 font-semibold text-navy">{{ $patient->name }}</dd>
                        <dd class="text-xs text-ink-muted">{{ $patient->medicalRecordNumber ?? 'Belum ada RM' }}</dd>
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
                {{--
                    Carried so the operator does not have to search twice. It is
                    a convenience, never a credential: the server re-validates
                    this id, re-derives the branch from the patient's own Nomor
                    RM and re-checks the operator's scope before anything is
                    written.
                --}}
                <input type="hidden" name="patient_id" value="{{ $patient->id }}">

                <x-ui.card title="3. Dokumen & Tanggal">
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
        @elseif ($lookup->isIdle())
            <x-ui.empty-state
                title="Belum ada pasien dipilih"
                description="Masukkan Nomor RM pasien terlebih dahulu untuk melihat cabang arsip dan batas tanggal yang berlaku."
            />
        @endif
    </div>
</x-settings-shell>
