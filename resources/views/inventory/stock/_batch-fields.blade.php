@php
    $batches = collect($batches ?? []);
    $allowCreate = $allowCreate ?? true;
    $showBatchSelector = $showBatchSelector ?? true;
    $batchMode = old('batch_mode', '');
@endphp

<div
    class="md:col-span-2 space-y-4 rounded-lg border border-gray-200 bg-gray-50 p-4"
    x-data="{ batchMode: @js($batchMode) }"
>
    <div>
        <p class="text-sm font-semibold text-gray-900">Batch</p>
        <p class="mt-1 text-xs text-gray-500">Opsional. Tautkan pergerakan ini ke identitas batch/lot untuk pelacakan stok.</p>
    </div>

    @if ($allowCreate)
        <div class="flex flex-wrap gap-2">
            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700">
                <input type="radio" name="batch_mode" value="existing" class="text-teal-600 focus:ring-teal-500" x-model="batchMode">
                Gunakan Batch yang Ada
            </label>
            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700">
                <input type="radio" name="batch_mode" value="new" class="text-teal-600 focus:ring-teal-500" x-model="batchMode">
                Buat Batch Baru
            </label>
        </div>
    @endif

    @if ($showBatchSelector)
        <div @unless($allowCreate) @else x-show="batchMode === 'existing'" x-cloak @endunless>
            <label for="inventory_batch_id" class="text-sm font-semibold text-gray-800">Batch</label>
            <select id="inventory_batch_id" name="inventory_batch_id" class="mt-2 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Tanpa batch</option>
                @foreach ($batches as $batch)
                    <option value="{{ $batch->id }}" @selected(old('inventory_batch_id') == $batch->id)>
                        {{ $batch->batch_number }}{{ $batch->lot_number ? ' · Lot '.$batch->lot_number : '' }}{{ $batch->expiry_date ? ' · Kedaluwarsa '.$batch->expiry_date->format('d/m/Y') : '' }}
                    </option>
                @endforeach
            </select>
            @error('inventory_batch_id')
                <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
            @enderror
        </div>
    @endif

    @if ($allowCreate)
        <div x-show="batchMode === 'new'" x-cloak class="grid gap-4 md:grid-cols-2">
            <div>
                <label for="batch_number" class="text-sm font-semibold text-gray-800">Nomor Batch</label>
                <input id="batch_number" type="text" name="batch_number" value="{{ old('batch_number') }}" maxlength="100" class="mt-2 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                @error('batch_number')
                    <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="lot_number" class="text-sm font-semibold text-gray-800">Nomor Lot</label>
                <input id="lot_number" type="text" name="lot_number" value="{{ old('lot_number') }}" maxlength="100" class="mt-2 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                @error('lot_number')
                    <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="received_date" class="text-sm font-semibold text-gray-800">Tanggal Terima</label>
                <input id="received_date" type="date" name="received_date" value="{{ old('received_date', now()->toDateString()) }}" class="mt-2 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                @error('received_date')
                    <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="expiry_date" class="text-sm font-semibold text-gray-800">Tanggal Kedaluwarsa</label>
                <input id="expiry_date" type="date" name="expiry_date" value="{{ old('expiry_date') }}" class="mt-2 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                @error('expiry_date')
                    <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                @enderror
            </div>
        </div>
    @endif
</div>
