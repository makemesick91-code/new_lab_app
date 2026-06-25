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
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Pasien</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->patient?->name ?? '—' }}</dd>
                </div>
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

                    // Sprint 59.2 — draw a saved baseline at the top of the (now
                    // taller) canvas while preserving its aspect ratio, so the
                    // existing handwriting is never vertically stretched and the
                    // added height simply extends downward as blank writing space.
                    function drawBaseline(img) {
                        const ratio = img.width > 0 ? canvas.width / img.width : 1;
                        const drawH = Math.min(img.height * ratio, canvas.height);
                        ctx.drawImage(img, 0, 0, canvas.width, drawH);
                    }

                    // Sprint 59.1 — load previously saved handwriting back into the
                    // canvas so new strokes are added on top of (and saved together
                    // with) the old handwriting. The canvas must never open blank
                    // when handwriting already exists.
                    if (existingSrc) {
                        const img = new Image();
                        img.crossOrigin = 'anonymous';
                        img.onload = function () {
                            drawBaseline(img);
                            baselineImg = img;
                            baselineLoaded = true;
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
                    document.getElementById('clear-canvas-btn').addEventListener('click', function () {
                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                        userDrew = false;
                        if (baselineLoaded && baselineImg) {
                            drawBaseline(baselineImg);
                        }
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
