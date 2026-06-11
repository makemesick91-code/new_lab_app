@php($supplier = $supplier ?? null)

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium text-gray-700">Nama</label>
        <input type="text" name="name" value="{{ old('name', $supplier?->name) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" required>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Telepon</label>
        <input type="text" name="phone" value="{{ old('phone', $supplier?->phone) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Email</label>
        <input type="email" name="email" value="{{ old('email', $supplier?->email) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
    </div>
    <div class="flex items-center pt-6">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $supplier?->is_active ?? true)) class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
        <label for="is_active" class="ml-2 text-sm text-gray-700">Aktif</label>
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Alamat</label>
        <textarea name="address" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('address', $supplier?->address) }}</textarea>
    </div>
</div>
