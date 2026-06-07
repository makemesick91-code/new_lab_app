@php($category = $category ?? null)

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="category-name" class="block text-sm font-medium text-gray-700">Nama <span class="text-rose-600">*</span></label>
        <input id="category-name" type="text" name="name" value="{{ old('name', $category?->name) }}" required
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
        @error('name')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="category-code" class="block text-sm font-medium text-gray-700">Kode</label>
        <input id="category-code" type="text" name="code" value="{{ old('code', $category?->code) }}"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm uppercase focus:border-teal-500 focus:ring-teal-500">
        @error('code')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="sm:col-span-2">
        <label for="category-description" class="block text-sm font-medium text-gray-700">Deskripsi</label>
        <textarea id="category-description" name="description" rows="4"
                  class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('description', $category?->description) }}</textarea>
        @error('description')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="flex items-center">
        <input type="hidden" name="is_active" value="0">
        <input id="category-is-active" type="checkbox" name="is_active" value="1" @checked(old('is_active', $category?->is_active ?? true))
               class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
        <label for="category-is-active" class="ml-2 text-sm text-gray-700">Aktif</label>
        @error('is_active')
            <p class="ml-3 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>
</div>
