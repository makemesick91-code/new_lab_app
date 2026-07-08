@php($treatment = $treatment ?? null)

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium text-gray-700">Kategori</label>
        <select name="treatment_category_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
            <option value="">- Pilih kategori -</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((int) old('treatment_category_id', $treatment?->treatment_category_id) === $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Kode</label>
        <input type="text" name="code" value="{{ old('code', $treatment?->code) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Nama</label>
        <input type="text" name="name" value="{{ old('name', $treatment?->name) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Durasi Default (menit)</label>
        <input type="number" name="default_duration_minutes" min="1" value="{{ old('default_duration_minutes', $treatment?->default_duration_minutes) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Status</label>
        <select name="is_active" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
            <option value="1" @selected((int) old('is_active', $treatment?->is_active ?? 1) === 1)>Aktif</option>
            <option value="0" @selected((int) old('is_active', $treatment?->is_active ?? 1) === 0)>Nonaktif</option>
        </select>
    </div>
    <div class="sm:col-span-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
            <input type="hidden" name="requires_doctor" value="0" />
            <input type="checkbox" name="requires_doctor" value="1" @checked((bool) old('requires_doctor', $treatment?->requires_doctor ?? true)) class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
            Perlu Dokter
        </label>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
            <input type="hidden" name="requires_room" value="0" />
            <input type="checkbox" name="requires_room" value="1" @checked((bool) old('requires_room', $treatment?->requires_room ?? true)) class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
            Perlu Ruangan
        </label>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
            <input type="hidden" name="requires_lab" value="0" />
            <input type="checkbox" name="requires_lab" value="1" @checked((bool) old('requires_lab', $treatment?->requires_lab ?? false)) class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
            Perlu Lab
        </label>
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Deskripsi</label>
        <textarea name="description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('description', $treatment?->description) }}</textarea>
    </div>
</div>
