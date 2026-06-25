<x-settings-shell title="Rekam Medis">
    @php
        $isFinal = $medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL;
    @endphp

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <h2 class="text-xl font-semibold text-gray-900">Rekam Medis</h2>
                    <x-ui.badge :tone="$isFinal ? 'success' : 'warning'">
                        {{ $isFinal ? 'Final' : 'Draft' }}
                    </x-ui.badge>
                </div>
                <p class="mt-1 text-sm text-gray-500">{{ $clinicVisit->visit_number }} &mdash; {{ $clinicVisit->visit_date?->format('d/m/Y') }}</p>
                @if ($clinicVisit->isFollowUpVisit())
                    <p class="mt-2 text-sm text-teal-800">
                        <span class="font-medium">Jenis Kunjungan:</span> {{ $clinicVisit->visitTypeLabel() }}
                        @if ($clinicVisit->followUpOf)
                            &mdash; <span class="font-medium">Kontrol dari:</span>
                            <a href="{{ route('rme.visits.show', $clinicVisit->followUpOf) }}" class="font-mono underline">{{ $clinicVisit->followUpOf->visit_number }}</a>
                        @endif
                    </p>
                @endif
            </div>

            {{-- Prev/next visit navigation (same patient, RM page) — Sprint 59 --}}
            @include('rme.visits.partials.visit-nav-arrows', [
                'prev' => $adjacentVisits['previous'] ?? null,
                'next' => $adjacentVisits['next'] ?? null,
                'routeName' => 'rme.visits.medical-record.show',
            ])
        </div>

        <x-ui.card title="Informasi Kunjungan">
            {{-- Sprint 59.4 — patient biodata rendered as a compact bordered
                 two-column table (label : value). KTP number is intentionally
                 never shown here, preserving RME privacy rules. Marital status
                 and religion have no column in the pilot schema, so they use the
                 safe "-" fallback (no migration added). --}}
            @php
                $patient = $clinicVisit->patient;
                $bioDash = '-';
                $genderLabels = ['Male' => 'Laki-laki', 'Female' => 'Perempuan', 'Other' => 'Lainnya'];

                // TTL / Umur — birth date (and age) only; the schema has no
                // place-of-birth column, so the place segment is omitted.
                if ($patient?->date_of_birth) {
                    $ttlUmur = $patient->date_of_birth->format('d-m-Y');
                    $patientAge = $patient->age();
                    if ($patientAge !== null) {
                        $ttlUmur .= ' / '.$patientAge.' tahun';
                    }
                } else {
                    $ttlUmur = $bioDash;
                }

                $bioLeft = [
                    ['Nama', $patient?->name ?: $bioDash],
                    ['TTL / Umur', $ttlUmur],
                    ['Pekerjaan', $patient?->occupation ?: $bioDash],
                    ['Status Pernikahan', $patient?->marital_status ?: $bioDash],
                    ['Alamat', $patient?->address ?: $bioDash],
                ];
                $bioRight = [
                    ['Jenis Kelamin', $patient?->gender ? ($genderLabels[$patient->gender] ?? $patient->gender) : $bioDash],
                    ['Agama', $patient?->religion ?: $bioDash],
                    ['No. Tlp / Wa', $patient?->whatsapp_number ?: ($patient?->phone ?: $bioDash)],
                    ['Email', $patient?->email ?: $bioDash],
                    ['No. RM', $patient?->medical_record_number ?: $bioDash],
                ];
            @endphp

            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <tbody>
                        @foreach (range(0, 4) as $i)
                            <tr class="align-top">
                                <th class="border border-gray-200 bg-gray-50 px-3 py-1.5 text-left font-semibold text-gray-700 whitespace-nowrap">{{ $bioLeft[$i][0] }}</th>
                                <td class="w-1/3 border border-gray-200 px-3 py-1.5 text-gray-900">: {{ $bioLeft[$i][1] }}</td>
                                <th class="border border-gray-200 bg-gray-50 px-3 py-1.5 text-left font-semibold text-gray-700 whitespace-nowrap">{{ $bioRight[$i][0] }}</th>
                                <td class="w-1/3 border border-gray-200 px-3 py-1.5 text-gray-900">: {{ $bioRight[$i][1] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Visit-level context retained below the patient biodata table. --}}
            <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dokter</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->doctor?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Tindakan Awal</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->initialTreatment?->name ?? '—' }}</dd>
                </div>
                @if ($clinicVisit->initial_service_note)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Catatan Layanan Awal</dt>
                        <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $clinicVisit->initial_service_note }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        {{-- Sprint 59.2 — "Riwayat Kunjungan Pasien" removed from the Medical
             Record page to declutter the doctor handwriting workflow and avoid
             loading visit-history data that is no longer rendered here. The
             history remains available on the clinic visit detail page. --}}

        <x-ui.card title="Riwayat Pencatatan">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dicatat oleh</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $medicalRecord->recordedBy?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dibuat pada</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ optional($medicalRecord->created_at)->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Diperbarui pada</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ optional($medicalRecord->updated_at)->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
                @if ($medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Difinalisasi pada</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ optional($medicalRecord->finalized_at)->format('d/m/Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Difinalisasi oleh</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $medicalRecord->finalizedBy?->name ?? '—' }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        @php
            $isDraft = $medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_DRAFT;
            $canUpdate = auth()->user()?->can('update', $medicalRecord) ?? false;
            $canFinalize = auth()->user()?->can('finalize', $medicalRecord) ?? false;
            // Sprint 59 — handwriting and notes remain editable after finalization.
            $canEditHandwriting = $canUpdate;
            $savedHandwriting = $medicalRecord->latestHandwriting();
            $hasHandwriting = $savedHandwriting !== null;
            $hasLegacySoap = filled($medicalRecord->subjective)
                || filled($medicalRecord->objective)
                || filled($medicalRecord->assessment)
                || filled($medicalRecord->plan);
        @endphp

        {{-- Sprint 59.2 — the typed "Catatan Rekam Medis" notes section is
             removed from the doctor UI. Handwriting RM is the primary clinical
             input, so the typed-notes editor only cluttered the workflow. The
             `notes` column and its update route are kept untouched for
             backward compatibility (print/detail views and the data layer). --}}

        @if ($hasLegacySoap)
            {{-- Legacy SOAP data (read-only; hidden from doctor input workflow) --}}
            <x-ui.card title="Data SOAP (Legacy)">
                <dl class="space-y-4">
                    @if (filled($medicalRecord->subjective))
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Subjective</dt>
                            <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $medicalRecord->subjective }}</dd>
                        </div>
                    @endif
                    @if (filled($medicalRecord->objective))
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Objective</dt>
                            <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $medicalRecord->objective }}</dd>
                        </div>
                    @endif
                    @if (filled($medicalRecord->assessment))
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Assessment</dt>
                            <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $medicalRecord->assessment }}</dd>
                        </div>
                    @endif
                    @if (filled($medicalRecord->plan))
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Plan</dt>
                            <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $medicalRecord->plan }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>
        @endif

        {{-- Handwriting RME preview (always) + canvas (draft editors only) --}}
        <x-ui.card
            :title="$canEditHandwriting ? 'RME Tulisan Tangan Lengkap' : 'RME Tulisan Tangan'"
            :description="$canEditHandwriting ? 'Isi Rekam Medis lengkap, tindakan, catatan tambahan, estimasi biaya/tindakan, dan tanda tangan dokter pada area handwriting berikut.' : null"
        >
            @if ($savedHandwriting && $savedHandwriting->previewUrl())
                <div class="{{ $canEditHandwriting ? 'mb-4' : '' }}">
                    <p class="text-xs {{ $canEditHandwriting ? 'text-emerald-700 font-medium' : 'text-gray-500' }} mb-2">
                        Tersimpan pada {{ $savedHandwriting->saved_at?->format('d/m/Y H:i') }}
                    </p>
                    <img src="{{ $savedHandwriting->previewUrl() }}"
                         alt="RME Tulisan Tangan"
                         class="border border-gray-300 rounded-lg max-w-full" />
                </div>
            @else
                <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                    Belum ada handwriting RM. Silakan isi dan simpan tulisan tangan sebelum finalisasi.
                </div>
            @endif

            @if ($canEditHandwriting)
                <form method="POST" action="{{ route('rme.visits.medical-record.handwriting.store', [$clinicVisit, $medicalRecord]) }}"
                      id="handwriting-form" class="{{ $savedHandwriting ? 'mt-4' : 'mt-3' }}">
                    @csrf
                    <input type="hidden" name="handwriting_data" id="handwriting-data-input">

                    @if ($savedHandwriting && $savedHandwriting->previewUrl())
                        <p class="mb-2 text-xs text-gray-500">
                            Tulisan tangan tersimpan dimuat ke kanvas di bawah. Lanjutkan menulis untuk menambah coretan tanpa menghapus yang lama.
                        </p>
                    @endif

                    {{-- Sprint 59.2 — taller canvas (extended downward) so the
                         doctor has more vertical handwriting space. Width is
                         unchanged at 900 and the element keeps max-width:100%
                         with height:auto, so it stays responsive and never
                         overflows horizontally. --}}
                    <canvas id="rme-canvas"
                            width="900" height="1100"
                            data-existing-src="{{ ($savedHandwriting && $savedHandwriting->previewUrl()) ? $savedHandwriting->previewUrl() : '' }}"
                            class="block w-full border border-gray-400 rounded-lg cursor-crosshair bg-white touch-none"
                            style="max-width:100%;height:auto;"></canvas>

                    @error('handwriting_data')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror

                    <div class="mt-3 flex items-center gap-3">
                        <x-ui.button type="button" variant="secondary" id="clear-canvas-btn">Reset ke Tulisan Tersimpan</x-ui.button>
                        <x-ui.button type="submit" variant="primary" id="save-handwriting-btn">Simpan Tulisan Tangan</x-ui.button>
                    </div>
                </form>

                <script>
                (function () {
                    const canvas = document.getElementById('rme-canvas');
                    const ctx = canvas.getContext('2d');
                    const form = document.getElementById('handwriting-form');
                    const input = document.getElementById('handwriting-data-input');
                    const existingSrc = canvas.dataset.existingSrc || '';
                    let drawing = false;
                    let userDrew = false;
                    let baselineImg = null;
                    let baselineLoaded = false;

                    // Sprint 59.3 — RM table template drawn directly onto the
                    // canvas so the doctor writes on a layout that mirrors the
                    // physical medical record sheet: a header row with three
                    // columns (narrow "Hari / Tanggal", wide "Pemeriksaan",
                    // narrow "Ket") above a large blank writing area.
                    const TEMPLATE = { headerH: 80, leftW: 135, rightW: 145 };

                    function drawTemplate() {
                        const w = canvas.width;
                        const h = canvas.height;
                        const midX1 = TEMPLATE.leftW;
                        const midX2 = w - TEMPLATE.rightW;

                        ctx.save();

                        // White sheet background.
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, w, h);

                        // Readable black table lines.
                        ctx.strokeStyle = '#111827';
                        ctx.lineWidth = 1.5;
                        ctx.beginPath();
                        // Outer border.
                        ctx.rect(0.75, 0.75, w - 1.5, h - 1.5);
                        // Column separators (full height).
                        ctx.moveTo(midX1, 0); ctx.lineTo(midX1, h);
                        ctx.moveTo(midX2, 0); ctx.lineTo(midX2, h);
                        // Header bottom border.
                        ctx.moveTo(0, TEMPLATE.headerH); ctx.lineTo(w, TEMPLATE.headerH);
                        ctx.stroke();

                        // Header labels.
                        ctx.fillStyle = '#111827';
                        ctx.font = '600 18px sans-serif';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        const headerMid = TEMPLATE.headerH / 2;
                        ctx.fillText('Hari /', midX1 / 2, headerMid - 11);
                        ctx.fillText('Tanggal', midX1 / 2, headerMid + 11);
                        ctx.fillText('Pemeriksaan', (midX1 + midX2) / 2, headerMid);
                        ctx.fillText('Ket', (midX2 + w) / 2, headerMid);

                        ctx.restore();
                    }

                    // Sprint 59.2 — draw a saved baseline at the top of the (now
                    // taller) canvas while preserving its aspect ratio, so the
                    // existing handwriting is never vertically stretched and the
                    // added height simply extends downward as blank writing space.
                    // Same-size PNGs (900x1100) are drawn 1:1.
                    function drawBaseline(img) {
                        const ratio = img.width > 0 ? canvas.width / img.width : 1;
                        const drawH = Math.min(img.height * ratio, canvas.height);
                        ctx.drawImage(img, 0, 0, canvas.width, drawH);
                    }

                    // Render order baseline: clear → white background + template →
                    // saved handwriting PNG on top (if any). Legacy transparent
                    // PNGs let the fresh template show through; newer PNGs already
                    // carry the template, so nothing is hidden.
                    function renderBase() {
                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                        drawTemplate();
                        if (baselineLoaded && baselineImg) {
                            drawBaseline(baselineImg);
                        }
                    }

                    // Draw the empty template immediately so the canvas is never
                    // blank while a saved image loads.
                    drawTemplate();

                    // Sprint 59.1 — load previously saved handwriting back into the
                    // canvas so new strokes are added on top of (and saved together
                    // with) the old handwriting. The canvas must never open blank
                    // when handwriting already exists.
                    if (existingSrc) {
                        const img = new Image();
                        img.crossOrigin = 'anonymous';
                        img.onload = function () {
                            baselineImg = img;
                            baselineLoaded = true;
                            renderBase();
                        };
                        img.onerror = function () {
                            // Baseline failed to load — keep it null so the submit
                            // guard blocks an accidental blank overwrite.
                            baselineLoaded = false;
                        };
                        img.src = existingSrc;
                    }

                    function getPos(e) {
                        const rect = canvas.getBoundingClientRect();
                        const scaleX = canvas.width / rect.width;
                        const scaleY = canvas.height / rect.height;
                        const src = e.touches ? e.touches[0] : e;
                        return {
                            x: (src.clientX - rect.left) * scaleX,
                            y: (src.clientY - rect.top) * scaleY,
                        };
                    }

                    function start(e) {
                        e.preventDefault();
                        drawing = true;
                        const p = getPos(e);
                        ctx.beginPath();
                        ctx.moveTo(p.x, p.y);
                    }

                    function draw(e) {
                        if (!drawing) return;
                        e.preventDefault();
                        userDrew = true;
                        const p = getPos(e);
                        ctx.lineWidth = 2;
                        ctx.lineCap = 'round';
                        ctx.strokeStyle = '#111827';
                        ctx.lineTo(p.x, p.y);
                        ctx.stroke();
                    }

                    function stop(e) {
                        drawing = false;
                    }

                    canvas.addEventListener('mousedown', start);
                    canvas.addEventListener('mousemove', draw);
                    canvas.addEventListener('mouseup', stop);
                    canvas.addEventListener('mouseleave', stop);
                    canvas.addEventListener('touchstart', start, { passive: false });
                    canvas.addEventListener('touchmove', draw, { passive: false });
                    canvas.addEventListener('touchend', stop);

                    // "Reset ke Tulisan Tersimpan" only discards the in-progress (unsaved) additions.
                    // When saved handwriting exists it is redrawn so the doctor
                    // never accidentally wipes previously stored content.
                    // "Reset ke Tulisan Tersimpan" is non-destructive: it only
                    // discards the in-progress (unsaved) additions and restores
                    // the saved baseline (template + saved handwriting). When no
                    // handwriting has been saved yet, it returns to the blank RM
                    // template only.
                    document.getElementById('clear-canvas-btn').addEventListener('click', function () {
                        userDrew = false;
                        renderBase();
                    });

                    form.addEventListener('submit', function (e) {
                        // Guard: never overwrite existing handwriting with a blank
                        // canvas. If a baseline exists but has not loaded and the
                        // doctor has not drawn anything new, block the save.
                        if (existingSrc && !baselineLoaded && !userDrew) {
                            e.preventDefault();
                            window.alert('Tulisan tangan tersimpan belum dimuat. Mohon tunggu sejenak lalu coba lagi agar tulisan lama tidak terhapus.');
                            return;
                        }
                        // Guard: with the template baked onto the canvas, a save
                        // with no strokes and no saved baseline would only persist
                        // the empty template. Block it so the empty-submit guard
                        // stays meaningful for brand-new records.
                        if (!existingSrc && !userDrew) {
                            e.preventDefault();
                            window.alert('Belum ada tulisan tangan untuk disimpan. Silakan tulis pada kanvas terlebih dahulu.');
                            return;
                        }
                        input.value = canvas.toDataURL('image/png');
                    });
                })();
                </script>
            @endif
        </x-ui.card>

        {{-- Finalize form: draft only, manager only --}}
        @if ($isDraft && $canFinalize)
            @if (! $hasHandwriting)
                <div class="rounded-lg bg-yellow-50 border border-yellow-300 px-4 py-3 text-sm text-yellow-800">
                    RME belum dapat difinalkan karena catatan tulis tangan dokter belum tersedia.
                </div>
                <button type="button" disabled
                        class="inline-flex items-center justify-center rounded-lg bg-gray-300 px-4 py-2 text-sm font-semibold text-gray-500 cursor-not-allowed">
                    Finalisasi
                </button>
            @else
                <form method="POST" action="{{ route('rme.visits.medical-record.finalize', [$clinicVisit, $medicalRecord]) }}">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                        Finalisasi
                    </button>
                </form>
            @endif
        @elseif (! $isDraft)
            <div class="rounded-lg bg-emerald-50 border border-emerald-300 px-4 py-3 text-sm text-emerald-800 font-medium">
                Rekam Medis ini telah difinalkan. Catatan dan tulisan tangan masih dapat diperbarui oleh dokter bila diperlukan.
            </div>
        @endif

        <div>
            <a href="{{ route('rme.visits.show', $clinicVisit) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali ke detail kunjungan</a>
        </div>
    </div>
</x-settings-shell>
