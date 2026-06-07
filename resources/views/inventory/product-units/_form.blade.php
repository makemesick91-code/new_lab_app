@php($unit = $unit ?? null)

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="unit-name" class="block text-sm font-medium text-gray-700">Nama <span class="text-rose-600">*</span></label>
        <input id="unit-name" type="text" name="name" value="{{ old('name', $unit?->name) }}" required
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
        @error('name')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="unit-symbol" class="block text-sm font-medium text-gray-700">Simbol <span class="text-rose-600">*</span></label>
        <input id="unit-symbol" type="text" name="symbol" value="{{ old('symbol', $unit?->symbol) }}" required
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
        @error('symbol')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="sm:col-span-2">
        <label for="unit-description" class="block text-sm font-medium text-gray-700">Deskripsi</label>
        <textarea id="unit-description" name="description" rows="4"
                  class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('description', $unit?->description) }}</textarea>
        @error('description')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="flex items-center">
        <input type="hidden" name="is_active" value="0">
        <input id="unit-is-active" type="checkbox" name="is_active" value="1" @checked(old('is_active', $unit?->is_active ?? true))
               class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
        <label for="unit-is-active" class="ml-2 text-sm text-gray-700">Aktif</label>
        @error('is_active')
            <p class="ml-3 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>
</div>
