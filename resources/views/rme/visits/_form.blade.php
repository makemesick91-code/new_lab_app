@php($visit = $visit ?? null)

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium text-gray-700">Klinik</label>
        <select name="clinic_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">- Pilih klinik -</option>
            @foreach ($clinics as $clinic)
                <option value="{{ $clinic->id }}" @selected(old('clinic_id', $visit?->clinic_id) == $clinic->id)>{{ $clinic->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Pasien</label>
        <select name="patient_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">- Pilih pasien -</option>
            @foreach ($patients as $patient)
                <option value="{{ $patient->id }}" @selected(old('patient_id', $visit?->patient_id) == $patient->id)>{{ $patient->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Dokter</label>
        <select name="doctor_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">- Pilih dokter -</option>
            @foreach ($doctors as $doctor)
                <option value="{{ $doctor->id }}" @selected(old('doctor_id', $visit?->doctor_id) == $doctor->id)>{{ $doctor->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Ruangan <span class="text-gray-400">(opsional)</span></label>
        <select name="clinic_room_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">- Tanpa ruangan -</option>
            @foreach ($clinicRooms as $room)
                <option value="{{ $room->id }}" @selected(old('clinic_room_id', $visit?->clinic_room_id) == $room->id)>{{ $room->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Keluhan Utama <span class="text-gray-400">(opsional)</span></label>
        <textarea name="chief_complaint" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('chief_complaint', $visit?->chief_complaint) }}</textarea>
    </div>
</div>
