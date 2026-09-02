@php($device = $device ?? null)

<div class="grid gap-4 md:grid-cols-2">
    <div class="md:col-span-2">
        <x-ui.input name="device_name" label="Nama Perangkat" required
                    :value="old('device_name', $device->device_name ?? '')"
                    placeholder="Contoh: Tablet Ruang A" />
    </div>

    <div>
        <x-ui.select name="branch_id" label="Cabang" required>
            <option value="">Pilih cabang</option>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}" @selected((int) old('branch_id', $device->branch_id ?? 0) === (int) $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </x-ui.select>
    </div>

    <div>
        <x-ui.input name="platform" label="Platform" :value="old('platform', $device->platform ?? 'android')" placeholder="android" />
    </div>

    <div>
        <x-ui.input name="device_model" label="Model Perangkat" :value="old('device_model', $device->device_model ?? '')" placeholder="Contoh: Galaxy Tab A9" />
    </div>

    <div>
        <x-ui.input name="os_version" label="Versi OS" :value="old('os_version', $device->os_version ?? '')" placeholder="15" />
    </div>

    <div>
        <x-ui.input name="app_version" label="Versi Aplikasi" :value="old('app_version', $device->app_version ?? '')" placeholder="1.0.0" />
    </div>

    <div class="md:col-span-2">
        <x-ui.textarea name="notes" label="Catatan" :value="old('notes', $device->notes ?? '')" rows="3" />
    </div>
</div>

<x-ui.alert variant="warning" class="mt-4">
    Perangkat yang didaftarkan di sini adalah <strong>catatan basis data</strong>, bukan perangkat yang sudah
    terbukti secara kriptografis. Verifikasi kunci perangkat baru dilakukan saat aplikasi Android melakukan
    enrolment pada tahap berikutnya.
</x-ui.alert>
