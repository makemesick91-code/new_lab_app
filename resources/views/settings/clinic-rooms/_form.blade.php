@php($room = $room ?? null)
@php
    $typeLabels = [
        'treatment_room' => 'Ruang Perawatan',
        'consultation_room' => 'Ruang Konsultasi',
        'xray_room' => 'Ruang Rontgen',
        'sterilization_room' => 'Ruang Sterilisasi',
        'lab_room' => 'Ruang Lab',
        'other' => 'Lainnya',
    ];
    $statusLabels = [
        'active' => 'Aktif',
        'inactive' => 'Nonaktif',
        'maintenance' => 'Pemeliharaan',
    ];
@endphp

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium text-gray-700">Kode</label>
        <input type="text" name="code" value="{{ old('code', $room?->code) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Nama</label>
        <input type="text" name="name" value="{{ old('name', $room?->name) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Tipe</label>
        <select name="type" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">- Pilih tipe -</option>
            @foreach ($types as $type)
                <option value="{{ $type }}" @selected(old('type', $room?->type) === $type)>{{ $typeLabels[$type] ?? $type }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Status</label>
        <select name="status" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">- Pilih status -</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected(old('status', $room?->status ?? 'active') === $status)>{{ $statusLabels[$status] ?? $status }}</option>
            @endforeach
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Deskripsi</label>
        <textarea name="description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $room?->description) }}</textarea>
    </div>
</div>
