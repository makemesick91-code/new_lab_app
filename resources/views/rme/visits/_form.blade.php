@php
    $visit = $visit ?? null;
    $treatments = $treatments ?? collect();
    $rmeBranches = $rmeBranches ?? collect();
    $patientMode = old('patient_mode', 'existing');
@endphp

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium text-gray-700">Klinik/Cabang <span class="text-gray-400">(Cabang RME)</span></label>
        <select name="branch_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            <option value="">- Pilih cabang RME -</option>
            @foreach ($rmeBranches as $branch)
                <option value="{{ $branch->id }}" @selected((int) old('branch_id', $visit?->branch_id) === $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
            @endforeach
        </select>
        @error('branch_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        <p class="mt-1 text-xs text-gray-400">Untuk pasien baru, cabang diambil dari pilihan Cabang RME di bawah.</p>
    </div>
    @if (! $visit)
        {{-- Pilih pasien terdaftar ATAU daftarkan pasien baru (Sprint 23 Phase 23.8) --}}
        <div class="sm:col-span-2 rounded-lg border border-teal-100 bg-teal-50/40 p-4" data-patient-block>
            <input type="hidden" name="patient_mode" data-patient-mode value="{{ $patientMode }}" />
            <div class="flex flex-wrap items-center gap-4">
                <span class="text-xs font-semibold uppercase tracking-wide text-teal-700">Pasien</span>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="radio" name="patient_mode_radio" value="existing" @checked($patientMode === 'existing') data-mode-radio class="text-teal-600 focus:ring-teal-500" />
                    Pasien Terdaftar
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="radio" name="patient_mode_radio" value="new" @checked($patientMode === 'new') data-mode-radio class="text-teal-600 focus:ring-teal-500" />
                    Pasien Baru
                </label>
            </div>

            {{-- Mode: pasien terdaftar --}}
            <div class="mt-3" data-mode-panel="existing">
                <label class="block text-sm font-medium text-gray-700">Pasien Terdaftar</label>
                <select name="patient_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">- Pilih pasien terdaftar -</option>
                    @foreach ($patients as $patient)
                        <option value="{{ $patient->id }}" @selected(old('patient_id', $visit?->patient_id) == $patient->id)>{{ $patient->medical_record_number ? $patient->medical_record_number.' — '.$patient->name : 'Belum ada RM — '.$patient->name }}</option>
                    @endforeach
                </select>
                @error('patient_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>

            {{-- Mode: pasien baru --}}
            <div class="mt-3 hidden" data-mode-panel="new">
                <p class="mb-2 text-xs text-gray-500">Nomor RM final dibentuk otomatis: <span class="font-mono">RM DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}</span>. Nomor RM manual diisi oleh admin.</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Nama Pasien</label>
                        <input type="text" name="new_patient[name]" value="{{ old('new_patient.name') }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                        @error('new_patient.name')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Cabang RME</label>
                        <select name="new_patient[branch_id]" data-rm-branch class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="" data-code="">- Pilih cabang RME -</option>
                            @foreach ($rmeBranches as $branch)
                                <option value="{{ $branch->id }}" data-code="{{ $branch->code }}" @selected((int) old('new_patient.branch_id') === $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('new_patient.branch_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Tanggal Daftar</label>
                        <input type="date" name="new_patient[registered_at]" data-rm-date value="{{ old('new_patient.registered_at', now()->format('Y-m-d')) }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Nomor RM Manual</label>
                        <input type="text" name="new_patient[manual_rm_number]" data-rm-manual inputmode="numeric" value="{{ old('new_patient.manual_rm_number') }}" placeholder="mis. 0001" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                        @error('new_patient.manual_rm_number')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Jenis Kelamin <span class="text-gray-400">(opsional)</span></label>
                        <select name="new_patient[gender]" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">—</option>
                            @foreach (['Male' => 'Laki-laki', 'Female' => 'Perempuan', 'Other' => 'Lainnya'] as $val => $label)
                                <option value="{{ $val }}" @selected(old('new_patient.gender') === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Tanggal Lahir <span class="text-gray-400">(opsional)</span></label>
                        <input type="date" name="new_patient[date_of_birth]" value="{{ old('new_patient.date_of_birth') }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Telepon <span class="text-gray-400">(opsional)</span></label>
                        <input type="text" name="new_patient[phone]" value="{{ old('new_patient.phone') }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Nomor RM Final (Preview)</label>
                        <input type="text" data-rm-preview readonly placeholder="RM DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}" class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50 font-mono text-sm text-gray-700" />
                    </div>
                </div>
            </div>
        </div>
    @else
        <div>
            <label class="block text-sm font-medium text-gray-700">Pasien</label>
            <select name="patient_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">- Pilih pasien terdaftar -</option>
                @foreach ($patients as $patient)
                    <option value="{{ $patient->id }}" @selected(old('patient_id', $visit?->patient_id) == $patient->id)>{{ $patient->medical_record_number ? $patient->medical_record_number.' — '.$patient->name : 'Belum ada RM — '.$patient->name }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div>
        <label class="block text-sm font-medium text-gray-700">Dokter</label>
        <select name="doctor_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            <option value="">- Pilih dokter -</option>
            @foreach ($doctors as $doctor)
                <option value="{{ $doctor->id }}" @selected(old('doctor_id', $visit?->doctor_id) == $doctor->id)>{{ $doctor->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Ruangan <span class="text-gray-400">(opsional)</span></label>
        <select name="clinic_room_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            <option value="">- Tanpa ruangan -</option>
            @foreach ($clinicRooms as $room)
                <option value="{{ $room->id }}" @selected(old('clinic_room_id', $visit?->clinic_room_id) == $room->id)>{{ $room->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Keluhan Utama <span class="text-gray-400">(opsional)</span></label>
        <textarea name="chief_complaint" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('chief_complaint', $visit?->chief_complaint) }}</textarea>
    </div>

    {{-- Initial Service (triage/antrian) — Phase 1.8 --}}
    <div class="sm:col-span-2 border-t border-gray-100 pt-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Layanan Awal (Triase)</p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-gray-700">
                    Tindakan Awal
                    @if (!$visit)
                        <span class="text-rose-500">*</span>
                    @endif
                </label>
                <select name="initial_treatment_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">- Pilih tindakan awal -</option>
                    @foreach ($treatments as $treatment)
                        <option value="{{ $treatment->id }}" @selected(old('initial_treatment_id', $visit?->initial_treatment_id) == $treatment->id)>
                            {{ $treatment->name }}
                        </option>
                    @endforeach
                </select>
                @error('initial_treatment_id')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Catatan Layanan Awal <span class="text-gray-400">(opsional)</span></label>
                <textarea name="initial_service_note" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('initial_service_note', $visit?->initial_service_note) }}</textarea>
            </div>
        </div>
        <p class="mt-2 text-xs text-gray-400">Tindakan awal hanya untuk konteks triase/antrian, bukan tagihan akhir.</p>
    </div>
</div>

@if (! $visit)
<script>
    (function () {
        const block = document.querySelector('[data-patient-block]');
        if (!block) return;

        const modeInput = block.querySelector('[data-patient-mode]');
        const radios = block.querySelectorAll('[data-mode-radio]');
        const panels = {
            existing: block.querySelector('[data-mode-panel="existing"]'),
            new: block.querySelector('[data-mode-panel="new"]'),
        };
        const branch = block.querySelector('[data-rm-branch]');
        const dateEl = block.querySelector('[data-rm-date]');
        const manual = block.querySelector('[data-rm-manual]');
        const preview = block.querySelector('[data-rm-preview]');

        const applyMode = function (mode) {
            modeInput.value = mode;
            panels.existing?.classList.toggle('hidden', mode !== 'existing');
            panels.new?.classList.toggle('hidden', mode !== 'new');
        };

        radios.forEach(r => r.addEventListener('change', () => applyMode(r.value)));

        const recompute = function () {
            if (!preview) return;
            const code = (branch?.selectedOptions[0]?.dataset.code || '').trim().toUpperCase();
            const year = (dateEl?.value || '').slice(0, 4);
            const num = (manual?.value || '').trim();
            preview.value = (code && year && num) ? `RM DG-${code}-${year}-${num}` : '';
        };

        [branch, dateEl, manual].forEach(el => el && el.addEventListener('input', recompute));
        branch && branch.addEventListener('change', recompute);

        applyMode(modeInput.value || 'existing');
        recompute();
    })();
</script>
@endif
