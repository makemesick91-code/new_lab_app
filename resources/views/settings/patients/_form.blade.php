@php($patient = $patient ?? null)

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium text-gray-700">Klinik</label>
        <select name="clinic_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">- Pilih klinik -</option>
            @foreach ($clinics as $clinic)
                <option value="{{ $clinic->id }}" @selected((int) old('clinic_id', $patient?->clinic_id) === $clinic->id)>{{ $clinic->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Dokter</label>
        <select name="doctor_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">- Pilih dokter -</option>
            @foreach ($doctors as $doctor)
                <option value="{{ $doctor->id }}" @selected((int) old('doctor_id', $patient?->doctor_id) === $doctor->id)>{{ $doctor->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Nomor Rekam Medis</label>
        <input type="text" name="medical_record_number" value="{{ old('medical_record_number', $patient?->medical_record_number) }}" placeholder="Kosongkan untuk dibuat otomatis" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
        @unless ($patient)
            <p class="mt-1 text-xs text-gray-500">Pasien baru: kosongkan untuk kode otomatis (mis. RM-{{ now()->format('Ym') }}-000001). Isi manual untuk pasien lama.</p>
        @endunless
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Nama</label>
        <input type="text" name="name" value="{{ old('name', $patient?->name) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Jenis Kelamin</label>
        <select name="gender" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">—</option>
            @foreach (['Male', 'Female', 'Other'] as $gender)
                <option value="{{ $gender }}" @selected(old('gender', $patient?->gender) === $gender)>{{ ['Male' => 'Laki-laki', 'Female' => 'Perempuan', 'Other' => 'Lainnya'][$gender] }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Tanggal Lahir</label>
        <input type="date" name="date_of_birth" value="{{ old('date_of_birth', optional($patient?->date_of_birth)->format('Y-m-d')) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Telepon</label>
        <input type="text" name="phone" value="{{ old('phone', $patient?->phone) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Alamat</label>
        <textarea name="address" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('address', $patient?->address) }}</textarea>
    </div>
    <div class="flex items-center">
        <input type="hidden" name="is_active" value="0" />
        <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $patient?->is_active ?? true)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
        <label for="is_active" class="ml-2 text-sm text-gray-700">Aktif</label>
    </div>
</div>
