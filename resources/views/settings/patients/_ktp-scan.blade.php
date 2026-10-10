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
    // PHASE-1 pilot: the capability flag AND server-verified pilot eligibility.
    $ktpOcrEnabled = app(\App\Modules\Patient\Services\KtpCameraOcrPilotGate::class)->allows(auth()->user(), request());
    $ktpFieldPrefix = $ktpFieldPrefix ?? '';
    $ktpOcrApplied = old('ktp_ocr_applied') === '1';
    // PHASE-3 pilot (decision D7): the approved consent wording, rendered verbatim.
    $ktpOcrConsentUsable = \App\Modules\Patient\Support\KtpOcrConsent::isUsable();
@endphp
<div class="sm:col-span-2 rounded-lg border border-indigo-100 bg-indigo-50/40 p-4"
     data-ktp-scan
     data-agent-url="{{ $scannerAgentUrl }}"
     data-health-url="{{ rtrim($scannerAgentUrl, '/') }}/health"
     data-scan-url="{{ rtrim($scannerAgentUrl, '/') }}/scan"
     data-upload-url="{{ route('settings.patients.ktp-scan.upload-temp') }}"
     data-parse-url="{{ $ktpOcrEnabled ? route('settings.patients.ktp-scan.parse-ocr') : '' }}"
     data-ocr-enabled="{{ $ktpOcrEnabled ? '1' : '0' }}"
     data-ocr-consent-version="{{ $ktpOcrEnabled && $ktpOcrConsentUsable ? \App\Modules\Patient\Support\KtpOcrConsent::version() : '' }}"
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
        {{-- PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1 — consent first (decision D7).
             Shown before the camera opens, and before OCR runs on an image from
             any source. The wording comes from config/patient_ktp_ocr_consent.php
             via KtpOcrConsent and is never paraphrased here. The answer lives only
             in this page: nothing is stored. The parse endpoint refuses OCR text
             sent without it. Declining keeps manual registration unchanged. --}}
        <div class="mt-3 hidden rounded-md border border-amber-300 bg-white p-4" data-ktp-consent
             role="region" aria-label="Persetujuan pemindaian KTP">
            @if ($ktpOcrConsentUsable)
                <p class="text-sm font-semibold text-gray-900" data-ktp-consent-title>{{ \App\Modules\Patient\Support\KtpOcrConsent::title() }}</p>
                <div class="mt-2 space-y-2 text-sm text-gray-800" data-ktp-consent-text>
                    @foreach (\App\Modules\Patient\Support\KtpOcrConsent::paragraphs() as $ktpConsentParagraph)
                        <p>{{ $ktpConsentParagraph }}</p>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-gray-500">
                    Bacakan atau perlihatkan teks di atas kepada pemilik KTP dan pastikan formulir persetujuan tertulis sudah ditandatangani.
                    Pembacaan otomatis bersifat pilihan: bila pemilik KTP tidak setuju, lanjutkan pendaftaran secara manual seperti biasa.
                    <span class="whitespace-nowrap">(Versi teks: {{ \App\Modules\Patient\Support\KtpOcrConsent::version() }})</span>
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" data-ktp-consent-accept
                        class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                        Pemilik KTP Setuju
                    </button>
                    <button type="button" data-ktp-consent-decline
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Tidak Setuju — Isi Manual
                    </button>
                </div>
            @else
                <p class="text-sm text-rose-700" data-ktp-consent-unavailable>
                    Teks persetujuan pemindaian KTP belum tersedia, sehingga pembacaan otomatis tidak dapat digunakan. Lanjutkan pendaftaran secara manual.
                </p>
            @endif
        </div>

        {{-- Camera. The static guide frame is the fallback crop (ID-1 ratio).
             REVISION-PATIENT-KTP-LIVE-FIELD-OVERLAY-OCR-1: once the camera is open
             (which needs the D7 "yes") a live overlay draws the card outline and the
             field boxes over the video and replaces the static guide. The overlay is
             a separate SVG layer — it is never part of the captured photo. --}}
        <div class="mt-3 hidden" data-ktp-camera>
            <div class="relative mx-auto w-full max-w-xl overflow-hidden rounded-lg bg-black" data-ktp-live-stage>
                <video data-ktp-video class="block max-h-[70vh] w-full object-contain" autoplay playsinline muted></video>
                <div class="pointer-events-none absolute inset-0 flex items-center justify-center" data-ktp-camera-guide>
                    <div class="w-[90%] rounded-xl border-4 border-white/90 shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]" style="aspect-ratio: 85.6 / 53.98;"></div>
                </div>
                <svg data-ktp-live-overlay class="pointer-events-none absolute left-0 top-0 hidden" aria-hidden="true" focusable="false"></svg>
            </div>
            <div class="mt-2 hidden flex flex-col items-center gap-1" data-ktp-live-panel role="status" aria-live="polite">
                <p class="text-sm font-medium">
                    <span data-ktp-live-level="red" class="hidden inline-flex items-center gap-1.5 rounded-full bg-danger-50 px-2.5 py-0.5 text-danger-700"><span class="h-2.5 w-2.5 rounded-full bg-danger" aria-hidden="true"></span>MERAH — KTP belum terdeteksi</span>
                    <span data-ktp-live-level="yellow" class="hidden inline-flex items-center gap-1.5 rounded-full bg-warning-50 px-2.5 py-0.5 text-warning-700"><span class="h-2.5 w-2.5 rounded-full bg-warning" aria-hidden="true"></span>KUNING — KTP terdeteksi, kualitas belum cukup</span>
                    <span data-ktp-live-level="green" class="hidden inline-flex items-center gap-1.5 rounded-full bg-success-50 px-2.5 py-0.5 text-success-700"><span class="h-2.5 w-2.5 rounded-full bg-success" aria-hidden="true"></span>HIJAU — posisi dan gambar tampak baik</span>
                </p>
                <p class="text-center text-xs text-gray-700" data-ktp-live-guidance></p>
                <p class="text-center text-[11px] text-gray-500">Hijau bukan jaminan hasil baca benar — tetap cocokkan setiap isian dengan KTP asli.</p>
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
        {{-- REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — field-based read.
             The card is deskewed and every field is read from its own box;
             boxes and corners are editable; each row can be read again.
             Rows, boxes and the corner editor are drawn by
             resources/js/ktp-roi-ui.js (DOM APIs only, no HTML from OCR). --}}
        <div class="mt-3 hidden rounded-md border border-gray-200 bg-white p-3" data-ktp-ocr-results>
            <p class="text-sm font-semibold text-gray-800">Hasil baca KTP (saran)</p>
            <p class="mb-2 text-xs text-gray-500">
                Hanya isian yang dicentang yang diterapkan. Status "Perlu dicek" dan "Berbeda — pilih" tidak dicentang otomatis;
                isian yang sudah Anda isi tidak diganti kecuali Anda mencentangnya. BACA ULANG tidak mengubah nilai yang sudah Anda terima.
            </p>
            <p class="mb-2 text-xs text-indigo-700" data-ktp-roi-boundary></p>
            {{-- PHASE-3 pilot measurement aid: counts only (no KTP value, no field
                 text), shown on this screen and never sent anywhere. The operator
                 copies it into the protocol §4 sheet. --}}
            <p class="mb-2 text-[11px] text-gray-500" data-ktp-roi-metrics></p>

            {{-- The card is shown large (boxes must be big enough to grab by
                 touch); the field rows sit below it. --}}
            <div class="space-y-3">
                <div class="max-w-3xl">
                    <div class="relative hidden select-none" data-ktp-roi-stage>
                        <canvas data-ktp-roi-card class="block h-auto w-full rounded-md border border-gray-200 bg-white" aria-label="KTP yang sudah diluruskan"></canvas>
                        <div class="absolute inset-0" data-ktp-roi-overlay></div>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" data-ktp-roi-corners-open data-ktp-roi-action="corners"
                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">Atur Sudut KTP</button>
                        <button type="button" data-ktp-roi-reset-boxes data-ktp-roi-action="reset"
                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">Kembalikan Posisi Kotak</button>
                        <button type="button" data-ktp-roi-retry-all data-ktp-roi-action="retry-all"
                            class="inline-flex items-center rounded-md border border-indigo-300 bg-white px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-50 disabled:opacity-50">Baca Ulang Semua Kotak</button>
                    </div>
                    <p class="mt-1 text-[11px] text-gray-500">Seret kotak untuk memindah, tarik titik tepinya untuk mengubah ukuran (atau pilih kotak lalu gunakan tombol panah / Shift+panah).</p>

                    <div class="mt-3 hidden rounded-md border border-amber-200 bg-amber-50/50 p-2" data-ktp-roi-corner-editor>
                        <p class="mb-1 text-xs font-medium text-amber-900">Atur sudut KTP pada foto asli</p>
                        <div class="relative select-none">
                            <canvas data-ktp-roi-source class="block h-auto w-full rounded border border-gray-200" aria-label="Foto KTP asli"></canvas>
                            <div class="absolute inset-0" data-ktp-roi-corner-overlay></div>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button type="button" data-ktp-roi-corners-apply data-ktp-roi-action="corners-apply"
                                class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50">Terapkan Sudut &amp; Baca Ulang</button>
                            <button type="button" data-ktp-roi-corners-cancel data-ktp-roi-action="corners-cancel"
                                class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 disabled:opacity-50">Batal</button>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs text-gray-500">
                            <tr>
                                <th class="px-2 py-1">Potongan</th>
                                <th class="px-2 py-1">Isian</th>
                                <th class="px-2 py-1">Nilai terbaca</th>
                                <th class="px-2 py-1">Status</th>
                                <th class="px-2 py-1">Aksi</th>
                                <th class="px-2 py-1">Terapkan</th>
                            </tr>
                        </thead>
                        <tbody data-ktp-ocr-rows></tbody>
                    </table>
                </div>
            </div>
            <button type="button" data-ktp-apply data-ktp-roi-action="apply" class="mt-3 inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Terapkan ke Formulir</button>
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
