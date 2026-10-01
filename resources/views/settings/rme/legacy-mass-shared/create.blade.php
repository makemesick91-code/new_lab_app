{{--
    FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 — package upload form.

    The only input is the archive. Patients, branches and dates all come from
    the manifest inside it and are resolved server-side, which is why this form
    has no patient picker and no branch selector to offer.
--}}
<x-settings-shell :title="$heading">
    <div class="space-y-6">
        <x-ui.page-header
            :title="$heading"
            subtitle="Unggah satu paket ZIP berisi manifest.csv dan berkas PDF arsip lama."
        >
            <x-slot:breadcrumb>Master Data RME / {{ $heading }} / Unggah Paket</x-slot:breadcrumb>

            <x-slot:actions>
                <x-ui.button :href="route($routePrefix.'.index')" variant="secondary">Kembali</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        @if ($errors->any())
            <x-ui.alert variant="danger">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <x-ui.card title="Cara menyiapkan paket">
            <div class="space-y-4 text-sm text-ink-soft">
                <p>
                    Paket harus berupa arsip <strong>ZIP</strong> yang memuat
                    <strong>manifest.csv</strong> di akar arsip, beserta seluruh berkas PDF
                    yang dirujuk manifest tersebut.
                </p>

                <div>
                    <p class="font-medium text-ink">Kolom manifest yang diharapkan</p>
                    <pre class="mt-2 overflow-x-auto rounded-xl bg-navy-50 p-3 text-xs text-ink">{{ implode(',', $template['headers']) }}
{{ implode(',', $template['example']) }}</pre>
                </div>

                <ul class="list-disc space-y-1 pl-5">
                    <li><strong>medical_record_number</strong> — Nomor RM lengkap seperti tertera pada dokumen. Nomor tidak lengkap akan ditolak.</li>
                    <li><strong>file_name</strong> — nama berkas PDF di dalam arsip, tepat sama.</li>
                    @if ($importType === \App\Modules\LegacyImport\Support\LegacyImportType::LEGACY_RME)
                        <li><strong>rme_date_earliest</strong> — tanggal paling awal pada dokumen.</li>
                        <li><strong>rme_date_latest</strong> — tanggal paling akhir pada dokumen. Kosongkan bila dokumen hanya memuat satu tanggal.</li>
                    @else
                        <li><strong>document_date</strong> — tanggal pada dokumen odontogram.</li>
                    @endif
                </ul>

                {{-- §12 — the multi-year rule, stated where the operator prepares the file. --}}
                <x-ui.alert variant="warning">
                    Satu pasien hanya boleh muncul <strong>satu kali</strong> pada manifest.
                    Bila arsip kertas seorang pasien terdiri dari beberapa tahun, gabungkan
                    menjadi <strong>satu berkas PDF</strong> dan tuliskan tanggal paling awal
                    serta paling akhir — bukan sebagai beberapa baris terpisah.
                </x-ui.alert>

                <p>
                    Tanggal wajib diverifikasi manusia sesuai yang tertera pada dokumen.
                    Sistem tidak membaca tanggal secara otomatis dari isi PDF.
                </p>

                <x-ui.button :href="route($routePrefix.'.manifest-template')" variant="secondary" size="sm">
                    Unduh Contoh Manifest
                </x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card title="Unggah paket">
            <form method="POST" action="{{ route($routePrefix.'.store') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf

                <div>
                    <label for="package" class="mb-1 block text-sm font-medium text-ink">Paket arsip (ZIP)</label>
                    <input
                        id="package"
                        type="file"
                        name="package"
                        accept=".zip"
                        required
                        class="block w-full rounded-xl border border-hairline bg-surface px-3 py-2 text-sm text-ink focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100"
                    >
                    @error('package')
                        <p class="mt-1 text-sm text-danger-700">{{ $message }}</p>
                    @enderror
                </div>

                <p class="text-xs text-ink-muted">
                    Setelah diunggah, paket diperiksa dan setiap baris dievaluasi. Anda akan
                    meninjau hasilnya terlebih dahulu — tidak ada dokumen yang dibuat pada
                    tahap ini.
                </p>

                <x-ui.button type="submit">Unggah dan Periksa</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</x-settings-shell>
