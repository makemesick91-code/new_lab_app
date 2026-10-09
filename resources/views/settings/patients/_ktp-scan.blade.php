{{-- Sprint 61.1 — Direct KTP Scanner Capture & Compression.
     REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — adds device-camera capture and
     browser-side OCR suggestions (behind the `patient.ktp_camera_ocr` flag).

     Every source (scanner agent, camera, manual file) ends the same way: the
     operator confirms the image, a compressed copy is parked privately under a
     temp token (attached to the patient only when the form is saved), and —
     when OCR is enabled — the text is read IN THIS BROWSER and the server
     returns validated SUGGESTIONS that the operator chooses to apply.

     Parameter: $ktpFieldPrefix — '' on the patient form, 'new_patient' inside
     the RME "Pasien Baru" panel (input names differ between the two forms).
     Logic: resources/js/ktp-camera-ocr.js. No inline script. --}}
@php
    $scannerAgentUrl = config('scanner.agent_url');
    $ktpOcrEnabled = app(\App\Services\Foundation\FeatureFlagService::class)->enabled('patient.ktp_camera_ocr');
    $ktpFieldPrefix = $ktpFieldPrefix ?? '';
    $ktpOcrApplied = old('ktp_ocr_applied') === '1';
@endphp
<div class="sm:col-span-2 rounded-lg border border-indigo-100 bg-indigo-50/40 p-4"
     data-ktp-scan
     data-agent-url="{{ $scannerAgentUrl }}"
     data-health-url="{{ rtrim($scannerAgentUrl, '/') }}/health"
     data-scan-url="{{ rtrim($scannerAgentUrl, '/') }}/scan"
     data-upload-url="{{ route('settings.patients.ktp-scan.upload-temp') }}"
     data-parse-url="{{ $ktpOcrEnabled ? route('settings.patients.ktp-scan.parse-ocr') : '' }}"
     data-ocr-enabled="{{ $ktpOcrEnabled ? '1' : '0' }}"
     data-ocr-build-base="{{ parse_url(asset('build'), PHP_URL_PATH) ?: '/build' }}"
     data-field-prefix="{{ $ktpFieldPrefix }}"
     data-csrf="{{ csrf_token() }}">
    <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700 mb-1">Scan KTP</p>
    <p class="mb-3 text-xs text-gray-500">
        Foto/scan KTP disimpan sebagai dokumen identitas privat pasien.
        @if ($ktpOcrEnabled)
            Teks KTP dibaca di perangkat ini dan hanya menjadi <span class="font-medium">saran</span> — periksa sebelum diterapkan.
        @else
            Pastikan <span class="font-medium">Daengtisia Scanner Agent</span> berjalan di komputer ini.
        @endif
    </p>

    <input type="hidden" name="ktp_scan_token" data-ktp-token value="{{ old('ktp_scan_token') }}" />
    <input type="hidden" name="ktp_ocr_applied" data-ktp-ocr-applied value="{{ $ktpOcrApplied ? '1' : '0' }}" />

    @if (old('ktp_scan_token'))
        <p class="mb-2 text-xs text-emerald-700">Foto KTP dari percobaan sebelumnya masih tersimpan dan akan dilampirkan saat pasien disimpan.</p>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        @if ($ktpOcrEnabled)
            <button type="button" data-ktp-camera-open
                class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Foto KTP dengan Kamera
            </button>
        @endif
        <button type="button" data-ktp-check
            class="inline-flex items-center rounded-md border border-indigo-300 bg-white px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50">
            Cek Scanner
        </button>
        <button type="button" data-ktp-scan-btn
            class="inline-flex items-center rounded-md border border-indigo-300 bg-white px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50 disabled:opacity-50"
            disabled>
            Scan KTP (Pemindai)
        </button>
        <button type="button" data-ktp-clear
            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 disabled:opacity-50"
            disabled>
            Hapus Preview
        </button>
    </div>

    <p class="mt-2 text-sm" data-ktp-status>
        Scanner belum terhubung. Jalankan Daengtisia Scanner Agent di komputer ini.
    </p>

    @if ($ktpOcrEnabled)
        {{-- Camera: the guide frame is the crop the capture keeps (ID-1 ratio). --}}
        <div class="mt-3 hidden" data-ktp-camera>
            <div class="relative mx-auto w-full max-w-xl overflow-hidden rounded-lg bg-black">
                <video data-ktp-video class="block w-full" autoplay playsinline muted></video>
                <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <div class="w-[90%] rounded-xl border-4 border-white/90 shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]" style="aspect-ratio: 85.6 / 53.98;"></div>
                </div>
            </div>
            <p class="mt-1 text-center text-xs text-gray-500">Letakkan KTP di dalam bingkai, hindari pantulan cahaya, pastikan teks tajam.</p>
            <div class="mt-2 flex flex-wrap justify-center gap-2">
                <button type="button" data-ktp-capture class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Ambil Foto</button>
                <button type="button" data-ktp-camera-switch class="hidden inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700">Ganti Kamera</button>
                <button type="button" data-ktp-camera-close class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700">Tutup Kamera</button>
            </div>
        </div>
    @endif

    <div class="mt-3 hidden" data-ktp-preview-wrap>
        <p class="mb-1 text-xs font-medium text-gray-600">Preview KTP</p>
        <img data-ktp-preview alt="Preview KTP" class="max-h-64 rounded-md border border-gray-200 bg-white" />
        <div class="mt-2 hidden flex flex-wrap gap-2" data-ktp-confirm-bar>
            <button type="button" data-ktp-confirm class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500">Gunakan Foto Ini</button>
            <button type="button" data-ktp-retake class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700">Ulangi</button>
        </div>
    </div>

    @if ($ktpOcrEnabled)
        <div class="mt-3 hidden rounded-md border border-gray-200 bg-white p-3" data-ktp-ocr-results>
            <p class="text-sm font-semibold text-gray-800">Hasil baca KTP (saran)</p>
            <p class="mb-2 text-xs text-gray-500">Hanya isian yang dicentang yang diterapkan. Status "Perlu dicek" tidak dicentang otomatis; isian yang sudah Anda isi tidak diganti kecuali Anda mencentangnya.</p>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs text-gray-500">
                        <tr><th class="px-2 py-1">Terapkan</th><th class="px-2 py-1">Isian</th><th class="px-2 py-1">Nilai terbaca</th><th class="px-2 py-1">Status</th></tr>
                    </thead>
                    <tbody data-ktp-ocr-rows></tbody>
                </table>
            </div>
            <div class="mt-2 hidden" data-ktp-ocr-info-wrap>
                <p class="text-xs font-medium text-gray-600">Data lain yang terbaca (tidak disimpan — tidak ada isiannya di formulir):</p>
                <ul class="ml-4 list-disc text-xs text-gray-600" data-ktp-ocr-info></ul>
            </div>
            <button type="button" data-ktp-apply class="mt-3 inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Terapkan ke Formulir</button>
        </div>
    @endif

    {{-- Shown once OCR values were applied; the server requires it then. --}}
    <div class="mt-3 {{ $ktpOcrApplied ? '' : 'hidden' }} rounded-md border border-amber-200 bg-amber-50 p-3" data-ktp-ocr-verify-wrap>
        <label class="flex items-start gap-2 text-sm text-amber-900">
            <input type="checkbox" name="ktp_ocr_verified" value="1" class="mt-0.5 rounded border-gray-300" @checked(old('ktp_ocr_verified')) @required($ktpOcrApplied) />
            <span>Saya sudah mencocokkan data hasil baca KTP dengan KTP asli pasien.</span>
        </label>
        @error('ktp_ocr_verified')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>

    {{-- Manual fallback — always available. --}}
    <details class="mt-3">
        <summary class="cursor-pointer text-xs font-medium text-gray-500">Unggah foto KTP secara manual</summary>
        <input type="file" accept="image/jpeg,image/png,image/webp" data-ktp-manual
            class="mt-2 block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700" />
        <p class="mt-1 text-xs text-gray-400">Gunakan jika kamera atau pemindai tidak tersedia. Berkas dikompres sebelum diunggah.</p>
    </details>
</div>
