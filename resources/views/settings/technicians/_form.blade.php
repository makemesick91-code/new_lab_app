@php($technician = $technician ?? null)

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium text-gray-700">Kode</label>
        <input type="text" name="code" value="{{ old('code', $technician?->code) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Nama</label>
        <input type="text" name="name" value="{{ old('name', $technician?->name) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Telepon</label>
        <input type="text" name="phone" value="{{ old('phone', $technician?->phone) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Email</label>
        <input type="email" name="email" value="{{ old('email', $technician?->email) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Spesialisasi</label>
        <input type="text" name="specialization" value="{{ old('specialization', $technician?->specialization) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">User Tertaut <span class="text-gray-400">(opsional)</span></label>
        <select name="user_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">- Tidak ada -</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}" @selected((int) old('user_id', $technician?->user_id) === $user->id)>{{ $user->name }} ({{ $user->email }})</option>
            @endforeach
        </select>
    </div>
    <div class="flex items-center mt-6">
        <input type="hidden" name="is_active" value="0" />
        <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $technician?->is_active ?? true)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
        <label for="is_active" class="ml-2 text-sm text-gray-700">Aktif</label>
    </div>
</div>
