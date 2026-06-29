<x-settings-shell title="Resep Dokter">
    @php
        $branch = $clinicVisit->branch;
        $branchTitle = strtoupper($branch?->name ?: 'TELKOMAS');
        $branchAddress = $branch?->address ?: 'Makassar';
        $branchPhone = $branch?->phone ?: '';
        $formValues = $prescription ? [
            'prescribed_by_name' => old('prescribed_by_name', $prescription->prescribed_by_name),
            'prescription_date' => old('prescription_date', $prescription->prescription_date?->format('Y-m-d')),
            'patient_name_snapshot' => old('patient_name_snapshot', $prescription->patient_name_snapshot),
            'patient_age_snapshot' => old('patient_age_snapshot', $prescription->patient_age_snapshot),
            'allergy_note' => old('allergy_note', $prescription->allergy_note),
            'pregnant_or_breastfeeding' => old('pregnant_or_breastfeeding', $prescription->pregnant_or_breastfeeding),
            'renal_function_issue' => old('renal_function_issue', $prescription->renal_function_issue),
            'notes' => old('notes', $prescription->notes),
        ] : [
            'prescribed_by_name' => old('prescribed_by_name', $defaults['prescribed_by_name']),
            'prescription_date' => old('prescription_date', $defaults['prescription_date']),
            'patient_name_snapshot' => old('patient_name_snapshot', $defaults['patient_name_snapshot']),
            'patient_age_snapshot' => old('patient_age_snapshot', $defaults['patient_age_snapshot']),
            'allergy_note' => old('allergy_note', $defaults['allergy_note']),
            'pregnant_or_breastfeeding' => old('pregnant_or_breastfeeding', $defaults['pregnant_or_breastfeeding']),
            'renal_function_issue' => old('renal_function_issue', $defaults['renal_function_issue']),
            'notes' => old('notes', ''),
        ];
        $rxCanvasSrc = $prescription?->prescriptionCanvasUrl();
        $sigCanvasSrc = $prescription?->signatureCanvasUrl();
    @endphp

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <h2 class="text-xl font-semibold text-gray-900">Resep Dokter</h2>
                    @if ($prescription)
                        <x-ui.badge tone="success">Tersimpan</x-ui.badge>
                    @else
                        <x-ui.badge tone="warning">Belum ada resep</x-ui.badge>
                    @endif
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $clinicVisit->visit_number }} &mdash; {{ $clinicVisit->patient?->name ?? 'Pasien' }}
                    &mdash; {{ $clinicVisit->visit_date?->format('d/m/Y') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('rme.visits.partials.visit-nav-arrows', [
                    'prev' => $adjacentVisits['prev'] ?? null,
                    'next' => $adjacentVisits['next'] ?? null,
                    'routeName' => 'rme.visits.prescription.show',
                ])
                @if ($prescription)
                    @can('print', $prescription)
                        <x-ui.button variant="secondary" :href="route('rme.prescriptions.print', $prescription)" target="_blank">
                            Print Resep
                        </x-ui.button>
                    @endcan
                    @if ($canManage && ! $editMode)
                        <x-ui.button variant="primary" :href="route('rme.visits.prescription.show', [$clinicVisit, 'edit' => 1])">
                            Edit Resep
                        </x-ui.button>
                    @endif
                @elseif ($canManage)
                    <x-ui.button variant="primary" :href="route('rme.visits.prescription.show', [$clinicVisit, 'edit' => 1])">
                        Buat Resep
                    </x-ui.button>
                @endif
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        @include('rme.visits.partials.visit-workflow-nav', [
            'clinicVisit' => $clinicVisit,
            'active' => 'prescription',
        ])

        {{-- Prescription paper layout --}}
        <div class="rounded-lg border border-gray-200 bg-white shadow-sm overflow-hidden">
            <div class="border-b border-gray-200 bg-gray-50 px-6 py-5 text-center">
                <div class="flex flex-col items-center gap-2 sm:flex-row sm:justify-center sm:gap-4">
                    <x-brand.daengtisia-logo class="h-12 w-auto max-w-[120px]" />
                    <div class="text-center sm:text-left">
                        <p class="text-lg font-bold text-gray-900">Klinik Gigi Daengtisia</p>
                        <p class="text-sm font-semibold text-teal-800">CABANG {{ $branchTitle }}</p>
                        <p class="text-xs text-gray-600">{{ $branchAddress }}@if ($branchPhone) &middot; {{ $branchPhone }}@endif</p>
                    </div>
                </div>
            </div>

            @if ($editMode && $canManage)
                <form method="POST"
                      action="{{ $prescription ? route('rme.prescriptions.update', $prescription) : route('rme.visits.prescription.store', $clinicVisit) }}"
                      id="prescription-form"
                      class="p-6 space-y-6">
                    @csrf
                    @if ($prescription)
                        @method('PATCH')
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="prescribed_by_name" class="block text-sm font-medium text-gray-700">Dari Dokter</label>
                            <input type="text" name="prescribed_by_name" id="prescribed_by_name" required
                                   value="{{ $formValues['prescribed_by_name'] }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 text-base py-2.5">
                            @error('prescribed_by_name')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="prescription_date" class="block text-sm font-medium text-gray-700">Tanggal Resep</label>
                            <input type="date" name="prescription_date" id="prescription_date" required
                                   value="{{ $formValues['prescription_date'] }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 text-base py-2.5">
                            @error('prescription_date')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="patient_name_snapshot" class="block text-sm font-medium text-gray-700">Nama Pasien</label>
                            <input type="text" name="patient_name_snapshot" id="patient_name_snapshot" required
                                   value="{{ $formValues['patient_name_snapshot'] }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 text-base py-2.5">
                            @error('patient_name_snapshot')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="patient_age_snapshot" class="block text-sm font-medium text-gray-700">Umur</label>
                            <input type="text" name="patient_age_snapshot" id="patient_age_snapshot"
                                   value="{{ $formValues['patient_age_snapshot'] }}"
                                   placeholder="tahun"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 text-base py-2.5">
                            @error('patient_age_snapshot')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="allergy_note" class="block text-sm font-medium text-gray-700">Alergi Obat</label>
                            <input type="text" name="allergy_note" id="allergy_note"
                                   value="{{ $formValues['allergy_note'] }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 text-base py-2.5">
                            @error('allergy_note')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="pregnant_or_breastfeeding" class="block text-sm font-medium text-gray-700">Hamil / Menyusui</label>
                            <input type="text" name="pregnant_or_breastfeeding" id="pregnant_or_breastfeeding"
                                   value="{{ $formValues['pregnant_or_breastfeeding'] }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 text-base py-2.5">
                            @error('pregnant_or_breastfeeding')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="renal_function_issue" class="block text-sm font-medium text-gray-700">Gangguan Fungsi Ginjal</label>
                            <input type="text" name="renal_function_issue" id="renal_function_issue"
                                   value="{{ $formValues['renal_function_issue'] }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 text-base py-2.5">
                            @error('renal_function_issue')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <label class="text-sm font-semibold text-gray-900">R/</label>
                            <x-ui.button type="button" variant="secondary" id="clear-prescription-canvas-btn" class="min-h-[44px]">
                                Bersihkan Resep
                            </x-ui.button>
                        </div>
                        <canvas id="prescription-canvas"
                                width="900" height="480"
                                data-existing-src="{{ $rxCanvasSrc }}"
                                class="mx-auto block w-full rounded-lg border-2 border-gray-300 bg-white cursor-crosshair touch-none"
                                style="max-width:100%;height:auto;min-height:240px;"></canvas>
                        <input type="hidden" name="prescription_canvas_data" id="prescription-canvas-data">
                        @error('prescription_canvas_data')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
                        <p class="mt-1 text-xs text-gray-500">Tulis resep manual di area R/ menggunakan mouse, stylus, atau jari.</p>
                    </div>

                    <div class="flex flex-col items-end">
                        <div class="w-full max-w-md">
                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <label class="text-sm font-semibold text-gray-900">Tanda Tangan Dokter</label>
                                <x-ui.button type="button" variant="secondary" id="clear-signature-canvas-btn" class="min-h-[44px]">
                                    Bersihkan Tanda Tangan
                                </x-ui.button>
                            </div>
                            <canvas id="signature-canvas"
                                    width="400" height="140"
                                    data-existing-src="{{ $sigCanvasSrc }}"
                                    class="block w-full rounded-lg border-2 border-gray-300 bg-white cursor-crosshair touch-none"
                                    style="max-width:100%;height:auto;"></canvas>
                            <input type="hidden" name="doctor_signature_canvas_data" id="signature-canvas-data">
                            @error('doctor_signature_canvas_data')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
                            <p class="mt-2 text-center text-sm text-gray-600">( drg. .................... )</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <x-ui.button type="submit" variant="primary" id="save-prescription-btn" class="min-h-[48px] px-6 text-base">
                            Simpan Resep
                        </x-ui.button>
                        @if ($prescription)
                            <x-ui.button type="button" variant="secondary" :href="route('rme.visits.prescription.show', $clinicVisit)" class="min-h-[48px]">
                                Batal Edit
                            </x-ui.button>
                        @endif
                    </div>
                </form>
            @else
                <div class="p-6 space-y-6">
                    <dl class="grid gap-4 sm:grid-cols-2 text-sm">
                        <div><dt class="font-medium text-gray-500">Dari Dokter</dt><dd class="mt-1 text-gray-900">{{ $formValues['prescribed_by_name'] ?: '—' }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Tanggal Resep</dt><dd class="mt-1 text-gray-900">{{ $prescription?->prescription_date?->format('d/m/Y') ?? '—' }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Nama Pasien</dt><dd class="mt-1 text-gray-900">{{ $formValues['patient_name_snapshot'] ?: '—' }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Umur</dt><dd class="mt-1 text-gray-900">{{ $formValues['patient_age_snapshot'] ?: '—' }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Alergi Obat</dt><dd class="mt-1 text-gray-900">{{ $formValues['allergy_note'] ?: '—' }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Hamil / Menyusui</dt><dd class="mt-1 text-gray-900">{{ $formValues['pregnant_or_breastfeeding'] ?: '—' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="font-medium text-gray-500">Gangguan Fungsi Ginjal</dt><dd class="mt-1 text-gray-900">{{ $formValues['renal_function_issue'] ?: '—' }}</dd></div>
                    </dl>

                    @if ($prescription)
                        <div>
                            <p class="mb-2 text-sm font-semibold text-gray-900">R/</p>
                            @if ($rxCanvasSrc)
                                <img src="{{ $rxCanvasSrc }}" alt="Resep dokter" class="mx-auto max-w-full rounded-lg border border-gray-200 bg-white">
                            @else
                                <p class="text-sm text-gray-500 italic">Belum ada gambar resep.</p>
                            @endif
                        </div>
                        <div class="flex justify-end">
                            <div class="w-full max-w-md text-center">
                                <p class="mb-2 text-sm font-semibold text-gray-900 text-left">Tanda Tangan Dokter</p>
                                @if ($sigCanvasSrc)
                                    <img src="{{ $sigCanvasSrc }}" alt="Tanda tangan dokter" class="mx-auto max-w-full rounded-lg border border-gray-200 bg-white">
                                @endif
                                <p class="mt-2 text-sm text-gray-600">( drg. {{ $formValues['prescribed_by_name'] ?: '....................' }} )</p>
                            </div>
                        </div>
                    @else
                        <p class="text-sm text-gray-500">Belum ada resep untuk kunjungan ini.</p>
                    @endif
                </div>
            @endif
        </div>

        @if ($history->isNotEmpty())
            <x-ui.card title="Riwayat Resep Pasien">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th scope="col" class="px-3 py-2">Tanggal</th>
                                <th scope="col" class="px-3 py-2">Kunjungan</th>
                                <th scope="col" class="px-3 py-2">Dokter</th>
                                <th scope="col" class="px-3 py-2">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($history as $item)
                                <tr>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $item->prescription_date?->format('d/m/Y') }}</td>
                                    <td class="px-3 py-2">{{ $item->clinicVisit?->visit_number ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ $item->prescribed_by_name }}</td>
                                    <td class="px-3 py-2">
                                        @can('viewForVisit', [\App\Modules\Prescription\Models\RmePrescription::class, $item->clinicVisit])
                                            <a href="{{ route('rme.visits.prescription.show', $item->clinic_visit_id) }}"
                                               class="font-medium text-teal-700 hover:text-teal-900">Lihat</a>
                                            @can('print', $item)
                                                <span class="text-gray-300 mx-1">|</span>
                                                <a href="{{ route('rme.prescriptions.print', $item) }}" target="_blank"
                                                   class="font-medium text-teal-700 hover:text-teal-900">Print</a>
                                            @endcan
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif

        <div>
            <a href="{{ route('rme.visits.show', $clinicVisit) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali ke detail kunjungan</a>
        </div>
    </div>

    @if ($editMode && $canManage)
        <script>
        (function () {
            function initPrescriptionCanvas(canvasId, hiddenId, clearBtnId, isUpdate) {
                const canvas = document.getElementById(canvasId);
                const hidden = document.getElementById(hiddenId);
                const clearBtn = document.getElementById(clearBtnId);
                if (!canvas || !hidden) return;

                const ctx = canvas.getContext('2d');
                let drawing = false;
                let userDrew = false;
                let activePointerId = null;
                let baselineImg = null;
                let baselineLoaded = false;
                const existingSrc = canvas.dataset.existingSrc || '';

                function renderBase() {
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    if (baselineLoaded && baselineImg) {
                        const ratio = baselineImg.width > 0 ? canvas.width / baselineImg.width : 1;
                        const drawH = Math.min(baselineImg.height * ratio, canvas.height);
                        ctx.drawImage(baselineImg, 0, 0, canvas.width, drawH);
                    }
                }

                function loadBaseline() {
                    userDrew = false;
                    baselineImg = null;
                    baselineLoaded = false;
                    renderBase();
                    if (!existingSrc) return;
                    const img = new Image();
                    img.crossOrigin = 'anonymous';
                    img.onload = function () {
                        baselineImg = img;
                        baselineLoaded = true;
                        renderBase();
                    };
                    img.src = existingSrc;
                }

                function getPos(e) {
                    const rect = canvas.getBoundingClientRect();
                    const scaleX = canvas.width / rect.width;
                    const scaleY = canvas.height / rect.height;
                    return {
                        x: (e.clientX - rect.left) * scaleX,
                        y: (e.clientY - rect.top) * scaleY,
                    };
                }

                function startDraw(e) {
                    if (e.pointerType === 'mouse' && e.button !== 0) return;
                    e.preventDefault();
                    drawing = true;
                    activePointerId = e.pointerId;
                    const p = getPos(e);
                    ctx.beginPath();
                    ctx.moveTo(p.x, p.y);
                    try { canvas.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
                }

                function moveDraw(e) {
                    if (!drawing || e.pointerId !== activePointerId) return;
                    e.preventDefault();
                    userDrew = true;
                    const p = getPos(e);
                    ctx.lineWidth = 2;
                    ctx.lineCap = 'round';
                    ctx.strokeStyle = '#111827';
                    ctx.lineTo(p.x, p.y);
                    ctx.stroke();
                }

                function endDraw(e) {
                    if (e.pointerId !== activePointerId) return;
                    drawing = false;
                    activePointerId = null;
                    try { canvas.releasePointerCapture(e.pointerId); } catch (err) { /* ignore */ }
                }

                canvas.addEventListener('pointerdown', startDraw);
                canvas.addEventListener('pointermove', moveDraw);
                canvas.addEventListener('pointerup', endDraw);
                canvas.addEventListener('pointercancel', endDraw);

                clearBtn?.addEventListener('click', function () {
                    userDrew = false;
                    baselineImg = null;
                    baselineLoaded = false;
                    renderBase();
                });

                loadBaseline();

                return {
                    serialize: function () {
                        if (userDrew || !isUpdate || !existingSrc) {
                            return canvas.toDataURL('image/png');
                        }
                        return '';
                    },
                    hasContent: function () {
                        return userDrew || existingSrc !== '';
                    },
                };
            }

            const isUpdate = {{ $prescription ? 'true' : 'false' }};
            const rx = initPrescriptionCanvas('prescription-canvas', 'prescription-canvas-data', 'clear-prescription-canvas-btn', isUpdate);
            const sig = initPrescriptionCanvas('signature-canvas', 'signature-canvas-data', 'clear-signature-canvas-btn', isUpdate);
            const form = document.getElementById('prescription-form');

            form?.addEventListener('submit', function (e) {
                const rxData = rx?.serialize() ?? '';
                const sigData = sig?.serialize() ?? '';

                if (!isUpdate) {
                    if (!rx?.hasContent() || !sig?.hasContent()) {
                        e.preventDefault();
                        window.alert('Area R/ dan tanda tangan dokter wajib diisi sebelum menyimpan.');
                        return;
                    }
                    document.getElementById('prescription-canvas-data').value = rxData;
                    document.getElementById('signature-canvas-data').value = sigData;
                } else {
                    document.getElementById('prescription-canvas-data').value = rxData;
                    document.getElementById('signature-canvas-data').value = sigData;
                }
            });
        })();
        </script>
    @endif
</x-settings-shell>
