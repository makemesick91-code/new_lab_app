@php
    $visit = $visit ?? null;
    $treatments = $treatments ?? collect();
@endphp

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium text-gray-700">Klinik</label>
        <select name="clinic_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            <option value="">- Pilih klinik -</option>
            @foreach ($clinics as $clinic)
                <option value="{{ $clinic->id }}" @selected(old('clinic_id', $visit?->clinic_id) == $clinic->id)>{{ $clinic->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Pasien</label>
        <select name="patient_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            <option value="">- Pilih pasien -</option>
            @foreach ($patients as $patient)
                <option value="{{ $patient->id }}" @selected(old('patient_id', $visit?->patient_id) == $patient->id)>{{ $patient->name }}</option>
            @endforeach
        </select>
    </div>
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
