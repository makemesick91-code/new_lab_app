{{-- Sprint 61.1 — Direct KTP Scanner Capture & Compression.
     Talks to the local Daengtisia Scanner Agent (localhost) from the operator
     browser, previews the scan, then uploads a compressed copy to Laravel which
     returns a temp token. The token is attached to the patient on save. --}}
@php
    $scannerAgentUrl = config('scanner.agent_url');
@endphp
<div class="sm:col-span-2 rounded-lg border border-indigo-100 bg-indigo-50/40 p-4"
     data-ktp-scan
     data-agent-url="{{ $scannerAgentUrl }}"
     data-health-url="{{ rtrim($scannerAgentUrl, '/') }}/health"
     data-scan-url="{{ rtrim($scannerAgentUrl, '/') }}/scan"
     data-upload-url="{{ route('settings.patients.ktp-scan.upload-temp') }}"
     data-csrf="{{ csrf_token() }}">
    <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700 mb-1">Scan KTP (Pemindai)</p>
    <p class="mb-3 text-xs text-gray-500">
        Hasil scan disimpan sebagai dokumen identitas privat pasien. Pastikan
        <span class="font-medium">Daengtisia Scanner Agent</span> berjalan di komputer ini.
    </p>

    <input type="hidden" name="ktp_scan_token" data-ktp-token value="" />

    <div class="flex flex-wrap items-center gap-2">
        <button type="button" data-ktp-check
            class="inline-flex items-center rounded-md border border-indigo-300 bg-white px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50">
            Cek Scanner
        </button>
        <button type="button" data-ktp-scan-btn
            class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
            disabled>
            Scan KTP
        </button>
        <button type="button" data-ktp-clear
            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 disabled:opacity-50"
            disabled>
            Hapus Preview
        </button>
    </div>

    <p class="mt-2 text-sm" data-ktp-status data-default="Scanner belum terhubung. Jalankan Daengtisia Scanner Agent di komputer ini.">
        Scanner belum terhubung. Jalankan Daengtisia Scanner Agent di komputer ini.
    </p>

    <div class="mt-3 hidden" data-ktp-preview-wrap>
        <p class="mb-1 text-xs font-medium text-gray-600">Preview KTP</p>
        <img data-ktp-preview alt="Preview KTP" class="max-h-64 rounded-md border border-gray-200 bg-white" />
    </div>

    {{-- Manual fallback — hidden behind a collapsed disclosure, never the main flow. --}}
    <details class="mt-3">
        <summary class="cursor-pointer text-xs font-medium text-gray-500">Upload manual sebagai cadangan</summary>
        <input type="file" accept="image/jpeg,image/png,image/webp" data-ktp-manual
            class="mt-2 block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700" />
        <p class="mt-1 text-xs text-gray-400">Gunakan hanya jika pemindai tidak tersedia. Berkas akan dikompres seperti hasil scan.</p>
    </details>
</div>

<script>
(function () {
    const root = document.querySelector('[data-ktp-scan]');
    if (!root) return;

    const healthUrl = root.dataset.healthUrl;
    const scanUrl = root.dataset.scanUrl;
    const uploadUrl = root.dataset.uploadUrl;
    const csrf = root.dataset.csrf;

    const checkBtn = root.querySelector('[data-ktp-check]');
    const scanBtn = root.querySelector('[data-ktp-scan-btn]');
    const clearBtn = root.querySelector('[data-ktp-clear]');
    const statusEl = root.querySelector('[data-ktp-status]');
    const tokenEl = root.querySelector('[data-ktp-token]');
    const previewWrap = root.querySelector('[data-ktp-preview-wrap]');
    const previewImg = root.querySelector('[data-ktp-preview]');
    const manualInput = root.querySelector('[data-ktp-manual]');

    const setStatus = (msg, tone) => {
        statusEl.textContent = msg;
        statusEl.className = 'mt-2 text-sm ' + ({
            ok: 'text-emerald-600',
            error: 'text-rose-600',
        }[tone] || 'text-gray-500');
    };

    const clearPreview = () => {
        tokenEl.value = '';
        previewImg.removeAttribute('src');
        previewWrap.classList.add('hidden');
        clearBtn.disabled = true;
    };

    const stripDataUri = (b64) => b64.includes(',') ? b64.split(',').pop() : b64;

    const uploadToServer = async (base64, mime, filename) => {
        const res = await fetch(uploadUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({
                document_type: 'ktp',
                image_base64: stripDataUri(base64),
                mime_type: mime || null,
                filename: filename || null,
            }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
            throw new Error(data.message || 'Upload gagal');
        }
        return data;
    };

    const showPreview = (dataUri) => {
        previewImg.src = dataUri;
        previewWrap.classList.remove('hidden');
        clearBtn.disabled = false;
    };

    checkBtn.addEventListener('click', async () => {
        setStatus('Memeriksa scanner…', 'info');
        try {
            const res = await fetch(healthUrl, { method: 'GET' });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.ok) {
                scanBtn.disabled = false;
                setStatus('Scanner terhubung' + (data.device ? ' — ' + data.device : ''), 'ok');
            } else {
                scanBtn.disabled = true;
                setStatus('Scanner tidak ditemukan', 'error');
            }
        } catch (e) {
            scanBtn.disabled = true;
            setStatus('Scanner belum terhubung. Jalankan Daengtisia Scanner Agent di komputer ini.', 'error');
        }
    });

    scanBtn.addEventListener('click', async () => {
        setStatus('Memindai KTP…', 'info');
        try {
            const res = await fetch(scanUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ document_type: 'ktp', mode: 'color', dpi: 200, max_width: 1600, quality: 82 }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok || !data.base64) {
                setStatus('Scanner tidak ditemukan', 'error');
                return;
            }
            const mime = data.mime_type || 'image/jpeg';
            showPreview('data:' + mime + ';base64,' + stripDataUri(data.base64));
            const uploaded = await uploadToServer(data.base64, mime, data.filename);
            tokenEl.value = uploaded.token;
            setStatus('Scan berhasil', 'ok');
        } catch (e) {
            setStatus('Upload gagal', 'error');
        }
    });

    clearBtn.addEventListener('click', clearPreview);

    if (manualInput) {
        manualInput.addEventListener('change', () => {
            const file = manualInput.files && manualInput.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = async () => {
                const dataUri = String(reader.result || '');
                showPreview(dataUri);
                setStatus('Mengunggah berkas…', 'info');
                try {
                    const uploaded = await uploadToServer(dataUri, file.type, file.name);
                    tokenEl.value = uploaded.token;
                    setStatus('Scan berhasil', 'ok');
                } catch (e) {
                    setStatus('Upload gagal', 'error');
                }
            };
            reader.readAsDataURL(file);
        });
    }
})();
</script>
