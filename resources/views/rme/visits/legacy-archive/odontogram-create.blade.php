{{--
    REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1 — unggah arsip
    odontogram lama dari sebuah KUNJUNGAN NYATA.

    SATU TANGGAL, bukan rentang. Arsip odontogram legacy memodelkan satu dokumen
    sebagai satu tanggal klinis representatif; tidak ada kolom tanggal akhir.
    Menyalin bentuk dua-tanggal milik RME ke sini akan menciptakan isian yang
    tidak punya sumber kebenaran.

    Tidak ada isian Nomor RM dokumen: arsip odontogram tidak memiliki layanan
    source-RM binding (kontrol itu dibangun untuk arsip RME). Menampilkan isian
    yang akan diabaikan akan menyiratkan perlindungan yang tidak ada.

    KTP/NIK tidak pernah ditampilkan.
--}}
<x-settings-shell title="Unggah Arsip Odontogram Lama">
    @php
        $maxSelectable = $visitDate->subDay()->toDateString();
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Unggah Arsip Odontogram Lama"
            subtitle="Dokumen odontogram historis milik pasien ini, diverifikasi tanggalnya sekarang."
        >
            <x-slot:breadcrumb>RME / Kunjungan / Arsip Legacy / Odontogram Lama</x-slot:breadcrumb>

            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.visits.show', $clinicVisit)">
                    Kembali ke Kunjungan
                </x-ui.button>
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

        <x-ui.card title="Kunjungan & Pasien">
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-gray-500">Pasien</dt>
                    <dd class="font-medium text-ink">{{ $patient->name }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Nomor RM</dt>
                    <dd class="font-medium text-ink">{{ $patient->medical_record_number }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Cabang Kunjungan</dt>
                    <dd class="font-medium text-ink">{{ $clinicVisit->branch?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Tanggal Kunjungan</dt>
                    <dd class="font-medium text-ink">{{ $visitDate->format('d-m-Y') }}</dd>
                </div>
            </dl>

            <x-ui.alert variant="info" class="mt-4">
                Tanggal dokumen harus <strong>lebih awal</strong> dari tanggal kunjungan ini
                ({{ $visitDate->format('d-m-Y') }}). Dokumen bertanggal sama dengan kunjungan
                harus melalui proses review Legacy standar.
            </x-ui.alert>
        </x-ui.card>

        <form
            method="POST"
            action="{{ route('rme.visits.legacy-archive.odontogram.store', $clinicVisit) }}"
            enctype="multipart/form-data"
            class="space-y-6"
        >
            @csrf

            <x-ui.card title="Tanggal pada Dokumen">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.input
                        type="date"
                        name="selected_odontogram_date"
                        label="Tanggal odontogram pada dokumen"
                        :value="old('selected_odontogram_date')"
                        :max="$maxSelectable"
                        required
                        help="Tanggal yang tertera pada dokumen odontogram."
                    />
                </div>
            </x-ui.card>

            <x-ui.card title="Berkas Dokumen">
                <input
                    type="file"
                    name="document"
                    accept="application/pdf"
                    required
                    class="block w-full rounded-lg border border-hairline bg-white px-3 py-2 text-sm text-ink file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700"
                >
                <p class="mt-2 text-xs text-gray-500">Hanya berkas PDF.</p>
            </x-ui.card>

            <x-ui.card title="Pernyataan Verifikasi">
                <label class="flex items-start gap-2 text-sm text-ink">
                    <input type="checkbox" name="date_attestation" value="1" required
                           class="mt-0.5 rounded border-hairline text-brand-600 focus:ring-brand-500">
                    <span>
                        Saya telah memeriksa dokumen asli dan memastikan tanggal yang dimasukkan
                        sesuai dengan dokumen.
                    </span>
                </label>

                <x-ui.alert variant="warning" class="mt-4">
                    Verifikasi tanggal ini menjadi bukti resmi. Pemeriksa berikutnya
                    <strong>tidak akan mengetik ulang tanggal</strong>. Pastikan tanggal benar
                    sebelum mengunggah.
                </x-ui.alert>
            </x-ui.card>

            <div class="flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" :href="route('rme.visits.show', $clinicVisit)">Batal</x-ui.button>
                <x-ui.button type="submit">Verifikasi &amp; Unggah</x-ui.button>
            </div>
        </form>
    </div>
</x-settings-shell>
