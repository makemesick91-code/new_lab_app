<x-settings-shell title="Persetujuan Tindakan Medis">
    @php
        $patient = $clinicVisit->patient;
        $template = $templates[$defaultTemplate] ?? reset($templates);
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Persetujuan Tindakan Medis"
            :subtitle="$clinicVisit->visit_number.' — '.($patient?->name ?? '-')"
        >
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.visits.show', $clinicVisit)">
                    &larr; Kembali ke Kunjungan
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.alert variant="warning" title="Pembayaran menunggu persetujuan.">
            Kasir belum dapat memproses pembayaran kunjungan ini sampai Surat Persetujuan Tindakan Medis
            ditandatangani oleh pasien atau keluarga yang berhak.
        </x-ui.alert>

        @if ($errors->any())
            <x-ui.alert variant="danger" title="Persetujuan belum dapat disimpan.">
                <ul class="list-disc space-y-1 pl-5 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <form method="POST" action="{{ route('rme.visits.consent.store', $clinicVisit) }}" id="consent-form" class="space-y-6">
            @csrf

            {{-- 1 — Pilih Form Consent --}}
            <x-ui.card title="1. Pilih Form Persetujuan">
                <x-ui.select name="template_code" label="Form Persetujuan" required>
                    @foreach ($templates as $code => $definition)
                        <option value="{{ $code }}" @selected(old('template_code', $defaultTemplate) === $code)>
                            {{ $definition['title'] }} (versi {{ $definition['version'] }})
                        </option>
                    @endforeach
                </x-ui.select>
            </x-ui.card>

            {{-- 2 — Identitas pemberi persetujuan --}}
            <x-ui.card title="2. Pemberi Persetujuan">
                <div class="space-y-4">
                    <x-ui.select name="consenter_relationship" label="Hubungan dengan Pasien" required id="consenter-relationship">
                        @foreach ($relationships as $value => $label)
                            <option value="{{ $value }}" @selected(old('consenter_relationship', 'self') === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>

                    <p class="text-xs text-ink-muted" id="consenter-self-note">
                        Bila pasien menandatangani sendiri, identitas diambil otomatis dari data pasien.
                    </p>

                    <div id="consenter-identity-fields" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.input name="consenter_name" label="Nama Pemberi Persetujuan" :value="old('consenter_name')" />
                        <x-ui.input name="consenter_age" label="Umur" :value="old('consenter_age')" />
                        <x-ui.select name="consenter_gender" label="Jenis Kelamin">
                            <option value="">—</option>
                            <option value="Female" @selected(old('consenter_gender') === 'Female')>Perempuan (P)</option>
                            <option value="Male" @selected(old('consenter_gender') === 'Male')>Laki-laki (L)</option>
                        </x-ui.select>
                        <x-ui.input name="consenter_identity_number" label="No KTP/SIM" :value="old('consenter_identity_number')" />
                        <div class="sm:col-span-2">
                            <x-ui.textarea name="consenter_address" label="Alamat" rows="2">{{ old('consenter_address') }}</x-ui.textarea>
                        </div>
                    </div>
                </div>
            </x-ui.card>

            {{-- 3 — Tindakan medis --}}
            <x-ui.card title="3. Tindakan Medis yang Disetujui">
                <div class="space-y-4">
                    <x-ui.textarea name="medical_action" label="Tindakan medis berupa" rows="2" required>{{ old('medical_action', $suggestedAction) }}</x-ui.textarea>
                    <x-ui.input name="treatment_summary" label="Jenis Perawatan" :value="old('treatment_summary', $suggestedAction)" />

                    <dl class="grid grid-cols-1 gap-3 rounded-lg bg-navy-50 p-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Pasien</dt>
                            <dd class="mt-1 text-ink">{{ $patient?->name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">No. Dental Record</dt>
                            <dd class="mt-1 font-mono text-ink">{{ $patient?->medical_record_number ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Dokter</dt>
                            <dd class="mt-1 text-ink">{{ $clinicVisit->doctor?->name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Cabang</dt>
                            <dd class="mt-1 text-ink">{{ $clinicVisit->branch?->name ?? '—' }}</dd>
                        </div>
                    </dl>
                    <p class="text-xs text-ink-muted">
                        Dokter yang tercantum pada surat persetujuan diambil dari dokter penanggung jawab kunjungan
                        ini dan tidak dapat diubah dari halaman ini.
                    </p>
                </div>
            </x-ui.card>

            {{-- 4 — Isi persetujuan yang dibaca pasien --}}
            <x-ui.card title="4. Isi Persetujuan">
                <p class="mb-3 text-sm text-ink-soft">{{ $template['clauses_intro'] }}</p>
                <ol class="list-decimal space-y-2 pl-5 text-sm text-ink">
                    @foreach ($template['clauses'] as $clause)
                        <li>{{ $clause }}</li>
                    @endforeach
                </ol>
                <p class="mt-4 text-sm text-ink">{{ $template['declaration'] }}</p>
            </x-ui.card>

            {{-- 5 — Clause 8: explicit YA/TIDAK --}}
            <x-ui.card title="5. Persetujuan Dokumentasi & Publikasi">
                <p class="mb-3 text-sm text-ink-soft">
                    Poin {{ $template['documentation_clause'] }} pada surat persetujuan. Jawaban ini wajib dipilih secara
                    tegas dan <span class="font-semibold">tidak memengaruhi</span> hak pasien atas perawatan maupun proses
                    pembayaran.
                </p>

                <div class="space-y-2">
                    <label class="flex items-start gap-3 rounded-lg border border-hairline p-3">
                        <input type="radio" name="documentation_consent" value="1" class="mt-1"
                               @checked(old('documentation_consent') === '1')>
                        <span class="text-sm text-ink"><span class="font-semibold">YA</span> — pasien menyetujui dokumentasi medis dan publikasi foto/video perawatan tanpa menampilkan informasi pribadi.</span>
                    </label>
                    <label class="flex items-start gap-3 rounded-lg border border-hairline p-3">
                        <input type="radio" name="documentation_consent" value="0" class="mt-1"
                               @checked(old('documentation_consent') === '0')>
                        <span class="text-sm text-ink"><span class="font-semibold">TIDAK</span> — pasien tidak menyetujui publikasi foto/video perawatan.</span>
                    </label>
                </div>
                @error('documentation_consent')<p class="mt-2 text-sm text-danger-700">{{ $message }}</p>@enderror
            </x-ui.card>

            {{-- 6 — Tanda tangan --}}
            <x-ui.card title="6. Tanda Tangan">
                <div class="space-y-6">
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-sm font-semibold text-ink">Yang membuat persetujuan (pasien/keluarga)</span>
                            <x-ui.button type="button" variant="secondary" id="clear-consenter-signature" class="min-h-[44px]">
                                Bersihkan
                            </x-ui.button>
                        </div>
                        <canvas id="consenter-signature-canvas" width="700" height="220"
                                class="w-full touch-none rounded-lg border border-hairline bg-white"
                                style="max-width:100%;height:auto;"></canvas>
                        <input type="hidden" name="consenter_signature" id="consenter-signature-data">
                        @error('consenter_signature')<p class="mt-1 text-sm text-danger-700">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-sm font-semibold text-ink">Dokter (opsional)</span>
                            <x-ui.button type="button" variant="secondary" id="clear-doctor-signature" class="min-h-[44px]">
                                Bersihkan
                            </x-ui.button>
                        </div>
                        <canvas id="doctor-signature-canvas" width="700" height="220"
                                class="w-full touch-none rounded-lg border border-hairline bg-white"
                                style="max-width:100%;height:auto;"></canvas>
                        <input type="hidden" name="doctor_signature" id="doctor-signature-data">
                        <p class="mt-1 text-xs text-ink-muted">
                            Nama dokter penanggung jawab tetap tercantum pada surat meskipun tanda tangan dokter
                            tidak diambil di sini.
                        </p>
                        @error('doctor_signature')<p class="mt-1 text-sm text-danger-700">{{ $message }}</p>@enderror
                    </div>
                </div>
            </x-ui.card>

            <div class="flex justify-end gap-3">
                <x-ui.button variant="secondary" :href="route('rme.visits.show', $clinicVisit)">Batal</x-ui.button>
                <x-ui.button type="submit" variant="primary" id="submit-consent">Simpan Persetujuan</x-ui.button>
            </div>
        </form>
    </div>

    <script>
    (function () {
        function initSignatureCanvas(canvasId, hiddenId, clearBtnId) {
            const canvas = document.getElementById(canvasId);
            const hidden = document.getElementById(hiddenId);
            const clearBtn = document.getElementById(clearBtnId);
            if (!canvas || !hidden) return null;

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

            return {
                serialize: function () {
                    return userDrew ? canvas.toDataURL('image/png') : '';
                },
                hasContent: function () {
                    return userDrew;
                },
                hidden: hidden,
            };
        }

        const consenter = initSignatureCanvas('consenter-signature-canvas', 'consenter-signature-data', 'clear-consenter-signature');
        const doctor = initSignatureCanvas('doctor-signature-canvas', 'doctor-signature-data', 'clear-doctor-signature');

        // "Saya sendiri" copies the patient's own canonical identity server-side,
        // so the typed fields are hidden to avoid implying they are authoritative.
        const relationship = document.getElementById('consenter-relationship');
        const identityFields = document.getElementById('consenter-identity-fields');
        const selfNote = document.getElementById('consenter-self-note');

        function syncRelationship() {
            const isSelf = relationship && relationship.value === 'self';
            if (identityFields) identityFields.style.display = isSelf ? 'none' : '';
            if (selfNote) selfNote.style.display = isSelf ? '' : 'none';
        }

        relationship?.addEventListener('change', syncRelationship);
        syncRelationship();

        document.getElementById('consent-form')?.addEventListener('submit', function (e) {
            if (consenter && !consenter.hasContent()) {
                e.preventDefault();
                alert('Tanda tangan pemberi persetujuan wajib diisi.');
                return;
            }
            if (consenter) consenter.hidden.value = consenter.serialize();
            if (doctor) doctor.hidden.value = doctor.serialize();
        });
    })();
    </script>
</x-settings-shell>
