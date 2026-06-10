<x-settings-shell title="Rekam Medis">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Rekam Medis</h3>
                    <p class="text-sm text-gray-500">{{ $clinicVisit->visit_number }} &mdash; {{ $clinicVisit->visit_date?->format('d/m/Y') }}</p>
                </div>
                <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold ring-1 ring-inset
                    {{ $medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL ? 'bg-green-50 text-green-700 ring-green-600/20' : 'bg-amber-50 text-amber-700 ring-amber-600/20' }}">
                    {{ $medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL ? 'Final' : 'Draft' }}
                </span>
            </div>

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

            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 border-t pt-4">
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
                @endif
            </dl>

            @php
                $isDraft = $medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_DRAFT;
                $canUpdate = auth()->user()?->can('update', $medicalRecord) ?? false;
                $canFinalize = auth()->user()?->can('finalize', $medicalRecord) ?? false;
            @endphp

            @if ($isDraft && $canUpdate)
                {{-- SOAP update form --}}
                <form method="POST" action="{{ route('rme.visits.medical-record.update', [$clinicVisit, $medicalRecord]) }}">
                    @csrf
                    @method('PATCH')

                    <div class="space-y-4">
                        <div>
                            <label for="subjective" class="block text-sm font-medium text-gray-700">Subjective</label>
                            <textarea id="subjective" name="subjective" rows="3"
                                      class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('subjective', $medicalRecord->subjective) }}</textarea>
                        </div>
                        <div>
                            <label for="objective" class="block text-sm font-medium text-gray-700">Objective</label>
                            <textarea id="objective" name="objective" rows="3"
                                      class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('objective', $medicalRecord->objective) }}</textarea>
                        </div>
                        <div>
                            <label for="assessment" class="block text-sm font-medium text-gray-700">Assessment</label>
                            <textarea id="assessment" name="assessment" rows="3"
                                      class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('assessment', $medicalRecord->assessment) }}</textarea>
                        </div>
                        <div>
                            <label for="plan" class="block text-sm font-medium text-gray-700">Plan</label>
                            <textarea id="plan" name="plan" rows="3"
                                      class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('plan', $medicalRecord->plan) }}</textarea>
                        </div>
                        <div>
                            <label for="notes" class="block text-sm font-medium text-gray-700">Catatan</label>
                            <textarea id="notes" name="notes" rows="2"
                                      class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes', $medicalRecord->notes) }}</textarea>
                        </div>
                    </div>

                    @if ($errors->any())
                        <div class="mt-3 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                            <ul class="list-disc list-inside space-y-1">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="mt-4">
                        <button type="submit"
                                class="inline-flex items-center rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-500">
                            Simpan Draft
                        </button>
                    </div>
                </form>
            @else
                {{-- Read-only SOAP (viewer role, or final record) --}}
                <dl class="space-y-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Subjective</dt>
                        <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $medicalRecord->subjective ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Objective</dt>
                        <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $medicalRecord->objective ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Assessment</dt>
                        <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $medicalRecord->assessment ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Plan</dt>
                        <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $medicalRecord->plan ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Catatan</dt>
                        <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $medicalRecord->notes ?? '—' }}</dd>
                    </div>
                </dl>
            @endif

            {{-- Handwriting RME Canvas — Phase 1.8 --}}
            @if ($isDraft && $canUpdate)
                @php $savedHandwriting = $medicalRecord->latestHandwriting(); @endphp
                <div class="border-t pt-6">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">RME Tulisan Tangan Lengkap</h4>
                    <p class="text-xs text-gray-400 mb-3">
                        Tuliskan RME lengkap, tindakan tambahan, estimasi biaya, dan tanda tangan dokter di area ini.
                    </p>

                    @if ($savedHandwriting)
                        <div class="mb-4">
                            <p class="text-xs text-green-700 font-medium mb-2">
                                Tersimpan pada {{ $savedHandwriting->saved_at?->format('d/m/Y H:i') }}
                            </p>
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($savedHandwriting->handwriting_path) }}"
                                 alt="RME Tulisan Tangan"
                                 class="border border-gray-300 rounded-md max-w-full" />
                        </div>
                    @endif

                    <form method="POST" action="{{ route('rme.visits.medical-record.handwriting.store', [$clinicVisit, $medicalRecord]) }}"
                          id="handwriting-form">
                        @csrf
                        <input type="hidden" name="handwriting_data" id="handwriting-data-input">

                        <canvas id="rme-canvas"
                                width="900" height="500"
                                class="block w-full border border-gray-400 rounded-md cursor-crosshair bg-white touch-none"
                                style="max-width:100%;height:auto;"></canvas>

                        @error('handwriting_data')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror

                        <div class="mt-3 flex items-center gap-3">
                            <button type="button" id="clear-canvas-btn"
                                    class="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                Bersihkan
                            </button>
                            <button type="submit" id="save-handwriting-btn"
                                    class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                                Simpan Tulisan Tangan
                            </button>
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

                        document.getElementById('handwriting-form').addEventListener('submit', function (e) {
                            document.getElementById('handwriting-data-input').value = canvas.toDataURL('image/png');
                        });
                    })();
                    </script>
                </div>
            @elseif ($medicalRecord->latestHandwriting())
                @php $savedHandwriting = $medicalRecord->latestHandwriting(); @endphp
                <div class="border-t pt-4">
                    <h4 class="text-sm font-semibold text-gray-700 mb-2">RME Tulisan Tangan</h4>
                    <p class="text-xs text-gray-500 mb-2">Tersimpan pada {{ $savedHandwriting->saved_at?->format('d/m/Y H:i') }}</p>
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($savedHandwriting->handwriting_path) }}"
                         alt="RME Tulisan Tangan"
                         class="border border-gray-300 rounded-md max-w-full" />
                </div>
            @endif

            {{-- Finalize form: draft only, manager only --}}
            @if ($isDraft && $canFinalize)
                <form method="POST" action="{{ route('rme.visits.medical-record.finalize', [$clinicVisit, $medicalRecord]) }}">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">
                        Finalisasi
                    </button>
                </form>
            @endif

            <div class="border-t pt-4">
                <a href="{{ route('rme.visits.show', $clinicVisit) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali ke detail kunjungan</a>
            </div>
        </div>
    </div>
</x-settings-shell>
