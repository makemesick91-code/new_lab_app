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

        @if ($clinicVisit->isFollowUpVisit() || ($patientVisitHistory ?? collect())->isNotEmpty())
            <x-ui.card title="Riwayat Kunjungan Pasien">
                @if (($patientVisitHistory ?? collect())->isEmpty())
                    <p class="text-sm text-gray-500">Tidak ada riwayat kunjungan sebelumnya.</p>
                @else
                    <div class="space-y-4">
                        @foreach ($patientVisitHistory as $historyVisit)
                            @php
                                $historyMr = $historyVisit->medicalRecord;
                                $historyOdontogram = $historyVisit->odontogram;
                            @endphp
                            <div class="rounded-lg border border-gray-200 p-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p class="font-mono text-sm font-semibold text-gray-900">{{ $historyVisit->visit_number }}</p>
                                        <p class="text-xs text-gray-500">{{ $historyVisit->visit_date?->format('d/m/Y') }} — {{ $historyVisit->visitTypeLabel() }} — {{ $historyVisit->doctor?->name ?? '—' }}</p>
                                    </div>
                                    <x-ui.button variant="secondary" :href="route('rme.visits.show', $historyVisit)">Buka Kunjungan</x-ui.button>
                                </div>
                                <dl class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt class="text-gray-500">Tindakan Awal</dt>
                                        <dd class="text-gray-900">{{ $historyVisit->initialTreatment?->name ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-500">Status RME</dt>
                                        <dd class="text-gray-900">{{ $historyMr ? strtoupper($historyMr->status) : 'Belum ada' }}</dd>
                                    </div>
                                    @if ($historyMr?->notes)
                                        <div class="sm:col-span-2">
                                            <dt class="text-gray-500">Catatan RME</dt>
                                            <dd class="text-gray-700 whitespace-pre-wrap">{{ \Illuminate\Support\Str::limit($historyMr->notes, 200) }}</dd>
                                        </div>
                                    @endif
                                    <div>
                                        <dt class="text-gray-500">Odontogram</dt>
                                        <dd class="text-gray-900">
                                            @if ($historyOdontogram)
                                                {{ $historyOdontogram->isFinalized() ? 'Final' : 'Draft' }}
                                                @can('create', [\App\Modules\Odontogram\Models\Odontogram::class, $historyVisit])
                                                    — <a href="{{ route('rme.visits.odontogram.show', $historyVisit) }}" class="text-teal-700 hover:text-teal-900">Lihat (read-only jika final)</a>
                                                @endcan
                                            @else
                                                Belum ada
                                            @endif
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        @endif

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

        {{-- Catatan Rekam Medis — primary doctor writing area (Sprint 59).
             Large, comfortable editor for long notes; editable on any visit
             (including older finalized ones). Pre-filled with existing content;
             partial saves never blank other fields. --}}
        <x-ui.card
            title="Catatan Rekam Medis"
            description="Area penulisan rekam medis dokter. Dapat diisi untuk kunjungan lama maupun baru dan tetap dapat diperbarui setelah finalisasi."
        >
            @if ($canUpdate)
                <form method="POST" action="{{ route('rme.visits.medical-record.update', [$clinicVisit, $medicalRecord]) }}">
                    @csrf
                    @method('PATCH')
                    <textarea
                        name="notes"
                        rows="18"
                        placeholder="Tulis catatan rekam medis lengkap di sini…"
                        class="block w-full min-h-[24rem] rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 text-sm leading-relaxed @error('notes') border-rose-300 @enderror"
                    >{{ old('notes', $medicalRecord->notes) }}</textarea>
                    @error('notes')
                        <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                    <div class="mt-3 flex justify-end">
                        <x-ui.button type="submit" variant="primary">Simpan Catatan</x-ui.button>
                    </div>
                </form>
            @else
                <p class="text-sm text-gray-700 whitespace-pre-wrap min-h-[6rem]">{{ $medicalRecord->notes ?: '—' }}</p>
            @endif
        </x-ui.card>

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

                    <canvas id="rme-canvas"
                            width="900" height="500"
                            class="block w-full border border-gray-400 rounded-lg cursor-crosshair bg-white touch-none"
                            style="max-width:100%;height:auto;"></canvas>

                    @error('handwriting_data')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror

                    <div class="mt-3 flex items-center gap-3">
                        <x-ui.button type="button" variant="secondary" id="clear-canvas-btn">Bersihkan</x-ui.button>
                        <x-ui.button type="submit" variant="primary" id="save-handwriting-btn">Simpan Tulisan Tangan</x-ui.button>
                    </div>
                </form>

                <script>
                (function () {
                    const canvas = document.getElementById('rme-canvas');
                    const ctx = canvas.getContext('2d');
                    let drawing = false;

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

                    document.getElementById('clear-canvas-btn').addEventListener('click', function () {
                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                    });

                    document.getElementById('handwriting-form').addEventListener('submit', function () {
                        document.getElementById('handwriting-data-input').value = canvas.toDataURL('image/png');
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
