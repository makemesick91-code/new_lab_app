{{--
    REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1 — unggah arsip RME lama
    dari sebuah KUNJUNGAN NYATA.

    Berbeda dari layar backlog: tidak ada pencarian pasien dan tidak ada pilihan
    cabang. Pasien, cabang dan tanggal kunjungan berasal dari kunjungan itu
    sendiri dan diselesaikan ulang di server pada saat submit — layar ini hanya
    menampilkannya.

    Operator memverifikasi tanggal dokumen SATU KALI di sini. Pemeriksa
    berikutnya menerima tanggal tersebut sebagai bukti READ-ONLY dan tidak
    mengetik ulang.

    `max` pada input tanggal hanyalah kenyamanan browser — seluruh batas
    (kunjungan, RME native, hari ini, tanggal lahir) dievaluasi ulang di server.

    KTP/NIK tidak pernah ditampilkan.
--}}
<x-settings-shell title="Unggah Arsip RME Lama">
    @php
        // Strictly earlier than the visit, so the latest selectable day is the
        // day before. Server-side the rule is `latest < visit_date`.
        $maxSelectable = $visitDate->subDay()->toDateString();
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Unggah Arsip RME Lama"
            subtitle="Dokumen historis milik pasien ini, diverifikasi tanggalnya sekarang."
        >
            <x-slot:breadcrumb>RME / Kunjungan / Arsip Legacy / RME Lama</x-slot:breadcrumb>

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
                Seluruh tanggal pada dokumen harus <strong>lebih awal</strong> dari tanggal
                kunjungan ini ({{ $visitDate->format('d-m-Y') }}). Dokumen bertanggal sama
                dengan kunjungan harus melalui proses review Legacy standar.
            </x-ui.alert>
        </x-ui.card>

        <form
            method="POST"
            action="{{ route('rme.visits.legacy-archive.rme.store', $clinicVisit) }}"
            enctype="multipart/form-data"
            class="space-y-6"
        >
            @csrf

            <x-ui.card title="Identitas pada Dokumen">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.input
                        name="source_rm_raw"
                        label="Nomor RM yang tertera pada dokumen"
                        :value="old('source_rm_raw')"
                        required
                        help="Salin persis seperti yang tertulis pada dokumen, bukan Nomor RM pasien di sistem."
                    />
                </div>
            </x-ui.card>

            <x-ui.card title="Tanggal pada Dokumen">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.input
                        type="date"
                        name="selected_rme_date"
                        label="Tanggal RME paling awal"
                        :value="old('selected_rme_date')"
                        :max="$maxSelectable"
                        required
                        help="Tanggal kunjungan tertua yang tertera pada dokumen."
                    />

                    <x-ui.input
                        type="date"
                        name="latest_rme_date"
                        label="Tanggal RME paling akhir"
                        :value="old('latest_rme_date')"
                        :max="$maxSelectable"
                        help="Kosongkan bila dokumen hanya memuat satu tanggal."
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
                <div class="space-y-3 text-sm text-ink">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="source_rm_confirmation" value="1" required
                               class="mt-0.5 rounded border-hairline text-brand-600 focus:ring-brand-500">
                        <span>Saya menyatakan Nomor RM yang saya masukkan tertera pada dokumen asli.</span>
                    </label>

                    {{--
                        THE attestation. This is the statement that removes the
                        duplicate date verification downstream, so it is worded
                        as a claim about the ORIGINAL document, not about the
                        form.
                    --}}
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="date_attestation" value="1" required
                               class="mt-0.5 rounded border-hairline text-brand-600 focus:ring-brand-500">
                        <span>
                            Saya telah memeriksa dokumen asli dan memastikan tanggal yang dimasukkan
                            sesuai dengan dokumen.
                        </span>
                    </label>
                </div>

                <x-ui.alert variant="warning" class="mt-4">
                    Verifikasi tanggal ini menjadi bukti resmi. Pemeriksa berikutnya
                    <strong>tidak akan mengetik ulang tanggal</strong> — mereka hanya memeriksa
                    dokumen dan kecocokan pasien. Pastikan tanggal benar sebelum mengunggah.
                </x-ui.alert>
            </x-ui.card>

            <div class="flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" :href="route('rme.visits.show', $clinicVisit)">Batal</x-ui.button>
                <x-ui.button type="submit">Verifikasi &amp; Unggah</x-ui.button>
            </div>
        </form>
    </div>
</x-settings-shell>
