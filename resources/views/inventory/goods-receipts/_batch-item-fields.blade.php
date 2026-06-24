@php
    $receiptDateDefault = old('receipt_date', optional($goodsReceipt?->receipt_date)->format('Y-m-d') ?? now()->toDateString());
@endphp

<div
    x-show="item.requires_batch_tracking"
    x-cloak
    class="mt-3 rounded-lg border border-teal-200 bg-teal-50 p-3"
>
    <p class="text-xs font-semibold uppercase tracking-wide text-teal-800">Batch / Lot</p>
    <p class="mt-1 text-xs font-semibold text-red-700">Produk ini wajib batch. Isi Nomor Batch sebelum Submit/Post Goods Receipt.</p>
    <p class="mt-1 text-xs text-teal-700">Pilih batch existing atau buat batch baru.</p>

    <div class="mt-3 flex flex-wrap gap-2">
        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-teal-200 bg-white px-3 py-2 text-xs font-medium text-gray-700">
            <input type="radio" :name="'items[' + index + '][batch_mode]'" value="existing" class="text-teal-600 focus:ring-teal-500" x-model="item.batch_mode">
            Batch Ada
        </label>
        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-teal-200 bg-white px-3 py-2 text-xs font-medium text-gray-700">
            <input type="radio" :name="'items[' + index + '][batch_mode]'" value="new" class="text-teal-600 focus:ring-teal-500" x-model="item.batch_mode">
            Batch Baru
        </label>
    </div>

    <div x-show="item.batch_mode === 'existing'" x-cloak class="mt-3">
        <label class="text-xs font-semibold text-gray-800">Batch</label>
        <select
            :name="'items[' + index + '][inventory_batch_id]'"
            x-model="item.inventory_batch_id"
            class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500"
        >
            <option value="">Pilih batch</option>
            <template x-for="batch in (batchesByProduct[item.product_id] || [])" :key="batch.id">
                <option :value="batch.id" x-text="batch.batch_number + (batch.lot_number ? ' · Lot ' + batch.lot_number : '') + (batch.expiry_date ? ' · exp ' + batch.expiry_date : '')"></option>
            </template>
        </select>
    </div>

    <div x-show="item.batch_mode === 'new'" x-cloak class="mt-3 grid gap-3 sm:grid-cols-2">
        <div>
            <label class="text-xs font-semibold text-gray-800">Nomor Batch <span class="text-red-600">*</span></label>
            <input
                type="text"
                maxlength="100"
                :name="'items[' + index + '][batch_number]'"
                x-model="item.batch_number"
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500"
            >
        </div>
        <div>
            <label class="text-xs font-semibold text-gray-800">Nomor Lot</label>
            <input
                type="text"
                maxlength="100"
                :name="'items[' + index + '][lot_number]'"
                x-model="item.lot_number"
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500"
            >
        </div>
        <div>
            <label class="text-xs font-semibold text-gray-800">Tanggal Terima Batch <span class="text-red-600">*</span></label>
            <input
                type="date"
                :name="'items[' + index + '][batch_received_date]'"
                x-model="item.batch_received_date"
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500"
            >
        </div>
        <div>
            <label class="text-xs font-semibold text-gray-800">Tanggal Kedaluwarsa</label>
            <input
                type="date"
                :name="'items[' + index + '][expiry_date]'"
                x-model="item.expiry_date"
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500"
            >
        </div>
    </div>
</div>
