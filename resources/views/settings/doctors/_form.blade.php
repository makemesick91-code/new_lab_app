@php($doctor = $doctor ?? null)
@php($selectedBranchIds = collect(old('branch_ids', $doctor?->branches?->pluck('id')->all() ?? []))->map(fn ($id) => (int) $id))

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700" for="branch_ids">Cabang Praktik yang Diizinkan <span class="text-rose-500">*</span></label>
        <select id="branch_ids" name="branch_ids[]" multiple size="5"
            class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            @foreach ($rmeBranches as $branch)
                <option value="{{ $branch->id }}" @selected($selectedBranchIds->contains($branch->id))>{{ $branch->code }} — {{ $branch->name }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-500">Tahan Ctrl (Windows) atau Cmd (Mac) untuk memilih lebih dari satu cabang.</p>
        @error('branch_ids')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
        @error('branch_ids.*')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Kode</label>
        <input type="text" name="code" value="{{ old('code', $doctor?->code) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Nama</label>
        <input type="text" name="name" value="{{ old('name', $doctor?->name) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Telepon</label>
        <input type="text" name="phone" value="{{ old('phone', $doctor?->phone) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Email</label>
        <input type="email" name="email" value="{{ old('email', $doctor?->email) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div class="flex items-center mt-6">
        <input type="hidden" name="is_active" value="0" />
        <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $doctor?->is_active ?? true)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
        <label for="is_active" class="ml-2 text-sm text-gray-700">Aktif</label>
    </div>
</div>
