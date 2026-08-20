@php($branch = $branch ?? null)

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium text-gray-700">Kode Cabang</label>
        <input type="text" name="code" value="{{ old('code', $branch?->code) }}" maxlength="20"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm uppercase focus:border-brand-500 focus:ring-brand-500" />
        <p class="mt-1 text-xs text-gray-500">
            Kode cabang diisi manual dan akan digunakan sebagai komponen format ID pasien.
        </p>
        @error('code')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Nama Cabang</label>
        <input type="text" name="name" value="{{ old('name', $branch?->name) }}" maxlength="150"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500" />
        @error('name')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
    </div>
    {{-- FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-01) — the branch address is the
         identity printed on that branch's clinical and financial documents. --}}
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Alamat Cabang</label>
        <textarea name="address" rows="2" maxlength="255"
                  class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('address', $branch?->address) }}</textarea>
        <p class="mt-1 text-xs text-gray-500">
            Dicetak pada dokumen cabang ini (rekam medis, odontogram, resep, dan nota pembayaran).
        </p>
        @error('address')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Telepon / WhatsApp Cabang</label>
        <input type="text" name="phone" value="{{ old('phone', $branch?->phone) }}" maxlength="30"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500" />
        @error('phone')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
    </div>
</div>

<div class="space-y-3">
    <label class="flex items-center gap-2 text-sm text-gray-700">
        <input type="hidden" name="is_active" value="0" />
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $branch?->is_active ?? true))
               class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
        <span>Aktif</span>
    </label>
    <label class="flex items-center gap-2 text-sm text-gray-700">
        <input type="hidden" name="is_rme_enabled" value="0" />
        <input type="checkbox" name="is_rme_enabled" value="1" @checked(old('is_rme_enabled', $branch?->is_rme_enabled ?? true))
               class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
        <span>Digunakan untuk RME</span>
    </label>
    <label class="flex items-center gap-2 text-sm text-gray-700">
        <input type="hidden" name="is_inventory_enabled" value="0" />
        <input type="checkbox" name="is_inventory_enabled" value="1" @checked(old('is_inventory_enabled', $branch?->is_inventory_enabled ?? true))
               class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
        <span>Digunakan untuk Inventory</span>
    </label>
    <p class="text-xs text-gray-400">
        Laboratorium bersifat global / tidak per-cabang, sehingga tidak ada opsi cabang untuk Lab.
    </p>
</div>
