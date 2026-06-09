@php($template = $template ?? null)

{{-- Safety notice --}}
<div class="rounded-md border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-800">
    <strong>Perhatian:</strong> Template ini hanya master data. Sistem belum mengirim WhatsApp otomatis pada fase ini.
</div>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium text-gray-700">Kode</label>
        <input type="text" name="code" value="{{ old('code', $template?->code) }}"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
               placeholder="Contoh: APPOINTMENT_REMINDER" />
        @error('code')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Nama</label>
        <input type="text" name="name" value="{{ old('name', $template?->name) }}"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Trigger Type</label>
        <select name="trigger_type" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">— Pilih Trigger —</option>
            @foreach ($triggerTypes as $type)
                <option value="{{ $type }}" @selected(old('trigger_type', $template?->trigger_type) === $type)>{{ $triggerTypeLabels[$type] }}</option>
            @endforeach
        </select>
        @error('trigger_type')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Audiens</label>
        <select name="audience_type" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">— Pilih Audiens —</option>
            @foreach ($audienceTypes as $type)
                <option value="{{ $type }}" @selected(old('audience_type', $template?->audience_type) === $type)>{{ $audienceTypeLabels[$type] }}</option>
            @endforeach
        </select>
        @error('audience_type')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Urutan</label>
        <input type="number" name="sort_order" min="0" value="{{ old('sort_order', $template?->sort_order ?? 0) }}"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
        @error('sort_order')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Status</label>
        <select name="is_active" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="1" @selected((int) old('is_active', $template?->is_active ?? 1) === 1)>Aktif</option>
            <option value="0" @selected((int) old('is_active', $template?->is_active ?? 1) === 0)>Nonaktif</option>
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Isi Pesan</label>
        <div class="mt-1 rounded-md border border-blue-100 bg-blue-50 p-3 text-xs text-blue-700 mb-1">
            Gunakan variabel berikut (akan diganti saat pengiriman):
            <code>@{{ patient_name }}</code>, <code>@{{ clinic_name }}</code>, <code>@{{ appointment_datetime }}</code>,
            <code>@{{ amount_due }}</code>, <code>@{{ due_date }}</code>, <code>@{{ service_name }}</code>,
            <code>@{{ installment_number }}</code>, <code>@{{ installment_amount }}</code>
        </div>
        <textarea name="message_body" rows="5"
                  class="block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                  placeholder="Halo @{{ patient_name }}, ...">{{ old('message_body', $template?->message_body) }}</textarea>
        @error('message_body')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Variabel Tersedia (opsional, pisahkan dengan enter)</label>
        <textarea name="available_variables_raw" rows="3"
                  class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                  placeholder="patient_name&#10;clinic_name&#10;appointment_datetime">{{ old('available_variables_raw', implode("\n", $template?->available_variables ?? [])) }}</textarea>
        <p class="mt-1 text-xs text-gray-400">Daftar nama variabel yang digunakan dalam template ini, satu per baris.</p>
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Deskripsi</label>
        <textarea name="description" rows="2"
                  class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $template?->description) }}</textarea>
        @error('description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
