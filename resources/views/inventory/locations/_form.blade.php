@php($location = $location ?? null)

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium text-gray-700">Nama</label>
        <input type="text" name="name" value="{{ old('name', $location?->name) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" required>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Kode</label>
        <input type="text" name="code" value="{{ old('code', $location?->code) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Tipe</label>
        <select name="type" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" required>
            @foreach ($types as $type)
                <option value="{{ $type }}" @selected(old('type', $location?->type) === $type)>{{ str_replace('_', ' ', $type) }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex items-center pt-6">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $location?->is_active ?? true)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
        <label for="is_active" class="ml-2 text-sm text-gray-700">Aktif</label>
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Deskripsi</label>
        <textarea name="description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $location?->description) }}</textarea>
    </div>
</div>
