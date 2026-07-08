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
    <x-ui.input name="code" label="Kode" :value="old('code', $room?->code)" />
    <x-ui.input name="name" label="Nama" :value="old('name', $room?->name)" />

    <x-ui.select name="type" label="Tipe">
        <option value="">- Pilih tipe -</option>
        @foreach ($types as $type)
            <option value="{{ $type }}" @selected(old('type', $room?->type) === $type)>{{ $typeLabels[$type] ?? $type }}</option>
        @endforeach
    </x-ui.select>

    <x-ui.select name="status" label="Status">
        <option value="">- Pilih status -</option>
        @foreach ($statuses as $status)
            <option value="{{ $status }}" @selected(old('status', $room?->status ?? 'active') === $status)>{{ $statusLabels[$status] ?? $status }}</option>
        @endforeach
    </x-ui.select>

    <div class="sm:col-span-2">
        <x-ui.textarea name="description" label="Deskripsi" rows="3" :value="old('description', $room?->description)" />
    </div>
</div>
