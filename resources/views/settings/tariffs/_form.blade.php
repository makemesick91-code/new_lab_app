@php($tariff = $tariff ?? null)

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Perawatan</label>
        <select name="treatment_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">- Pilih perawatan -</option>
            @foreach ($treatments as $treatment)
                <option value="{{ $treatment->id }}" @selected((int) old('treatment_id', $tariff?->treatment_id) === $treatment->id)>{{ $treatment->code }} — {{ $treatment->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Harga (Rp)</label>
        <input type="number" name="price" min="0" step="0.01" value="{{ old('price', $tariff?->price) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Tanggal Berlaku</label>
        <input type="date" name="effective_date" value="{{ old('effective_date', $tariff?->effective_date?->format('Y-m-d')) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Status</label>
        <select name="is_active" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="1" @selected((int) old('is_active', $tariff?->is_active ?? 1) === 1)>Aktif</option>
            <option value="0" @selected((int) old('is_active', $tariff?->is_active ?? 1) === 0)>Nonaktif</option>
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Catatan</label>
        <textarea name="notes" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes', $tariff?->notes) }}</textarea>
    </div>
</div>
