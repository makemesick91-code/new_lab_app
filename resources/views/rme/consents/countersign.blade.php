<x-settings-shell title="Tanda Tangani PTM">
    {{--
        DMS memo M1.1–M1.3, PR1 (rule 241 §1) — the treating doctor countersigns the
        PERSETUJUAN TINDAKAN MEDIS the patient signed. The document is shown read-only
        first; the only input is the drawn signature. The doctor, the account and the
        time come from the server (the locked visit, the consent, the signed-in
        account, ClinicalClock), never from this form.

        The canvas field is `doctor_signature`, already in the exception handler's
        dontFlash list (rule 237 §3), and is never restored with old().
    --}}
    <div class="space-y-6">
        <x-ui.page-header
            title="Tanda Tangani PTM"
            :subtitle="$consent->consent_number.' — '.($consent->patient_name_snapshot ?: '-')"
        >
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.consents.show', $consent)">
                    &larr; Kembali ke Persetujuan
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.alert variant="info" title="Tanda tangan dokter pada Persetujuan Tindakan Medis">
            Pasien atau pemberi persetujuan sudah menandatangani persetujuan di bawah ini. Periksa isinya,
            lalu tanda tangani sebagai dokter yang menangani kunjungan ini. Tanda tangan dokter disimpan
            sebagai catatan tersendiri dan tidak mengubah persetujuan yang sudah ditandatangani pasien.
        </x-ui.alert>

        @if ($errors->any())
            <x-ui.alert variant="danger" title="Tanda tangan dokter belum dapat disimpan.">
                <ul class="list-disc space-y-1 pl-5 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <x-ui.card>
            @include('rme.consents.partials.document-styles')
            @include('rme.consents.partials.document')
        </x-ui.card>

        <form method="POST" action="{{ route('rme.consents.countersign', $consent) }}" id="countersign-form" class="space-y-6">
            @csrf

            <x-ui.card title="Tanda Tangan Dokter">
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-sm font-semibold text-ink">{{ $consent->doctor_name_snapshot ?: 'Dokter' }}</span>
                        <x-ui.button type="button" variant="secondary" id="clear-doctor-countersignature" class="min-h-[44px]">
                            Bersihkan
                        </x-ui.button>
                    </div>
                    <canvas id="doctor-countersignature-canvas" width="700" height="220"
                            class="w-full touch-none rounded-lg border border-hairline bg-white"
                            style="max-width:100%;height:auto;"></canvas>
                    <input type="hidden" name="doctor_signature" id="doctor-countersignature-data">
                    @error('doctor_signature')<p class="mt-1 text-sm text-danger-700">{{ $message }}</p>@enderror
                    @error('consent')<p class="mt-1 text-sm text-danger-700">{{ $message }}</p>@enderror
                </div>
            </x-ui.card>

            <div class="flex justify-end gap-3">
                <x-ui.button variant="secondary" :href="route('rme.consents.show', $consent)">Batal</x-ui.button>
                <x-ui.button type="submit" variant="primary" id="submit-countersign">Tanda Tangani PTM</x-ui.button>
            </div>
        </form>
    </div>

    <script>
    (function () {
        const canvas = document.getElementById('doctor-countersignature-canvas');
        const hidden = document.getElementById('doctor-countersignature-data');
        const clearBtn = document.getElementById('clear-doctor-countersignature');
        if (!canvas || !hidden) return;

        const ctx = canvas.getContext('2d');
        let drawing = false;
        let userDrew = false;
        let activePointerId = null;

        function renderBase() {
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        }

        function getPos(e) {
            const rect = canvas.getBoundingClientRect();
            return {
                x: (e.clientX - rect.left) * (canvas.width / rect.width),
                y: (e.clientY - rect.top) * (canvas.height / rect.height),
            };
        }

        canvas.addEventListener('pointerdown', function (e) {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            e.preventDefault();
            drawing = true;
            activePointerId = e.pointerId;
            const p = getPos(e);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
            try { canvas.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
        });

        canvas.addEventListener('pointermove', function (e) {
            if (!drawing || e.pointerId !== activePointerId) return;
            e.preventDefault();
            userDrew = true;
            const p = getPos(e);
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.strokeStyle = '#111827';
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
        });

        function endDraw(e) {
            if (e.pointerId !== activePointerId) return;
            drawing = false;
            activePointerId = null;
            try { canvas.releasePointerCapture(e.pointerId); } catch (err) { /* ignore */ }
        }

        canvas.addEventListener('pointerup', endDraw);
        canvas.addEventListener('pointercancel', endDraw);

        clearBtn?.addEventListener('click', function () {
            userDrew = false;
            renderBase();
        });

        renderBase();

        document.getElementById('countersign-form')?.addEventListener('submit', function (e) {
            if (!userDrew) {
                e.preventDefault();
                alert('Tanda tangan dokter wajib diisi.');
                return;
            }
            hidden.value = canvas.toDataURL('image/png');
        });
    })();
    </script>
</x-settings-shell>
