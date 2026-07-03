@php
    use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestType;
    use App\Modules\Inventory\Models\InventoryBatch;
    use App\Modules\Inventory\Models\InventoryBatchDisposalRequest;
@endphp

@can('createForBatch', [InventoryBatchDisposalRequest::class, $batch])
    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm" id="buat-permintaan-disposal">
        <h3 class="text-base font-semibold text-gray-900">Buat Permintaan Disposal/Adjustment</h3>
        <p class="mt-1 text-sm text-amber-800">
            Permintaan ini tidak mengubah stok. Stok hanya berkurang setelah finalisasi adjustment resmi.
        </p>

        <form method="POST" action="{{ route('inventory.batches.disposal-requests.store', $batch) }}" class="mt-4 space-y-4">
            @csrf
            <div>
                <label for="disposal-location-{{ $batch->id }}" class="text-sm font-medium text-gray-700">Lokasi Stok</label>
                <select id="disposal-location-{{ $batch->id }}" name="inventory_location_id" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">Pilih lokasi dengan stok batch…</option>
                    @foreach ($stockByLocation as $row)
                        @php $locStock = (float) ($row->derived_stock ?? 0); @endphp
                        @if ($locStock > 0 && $row->inventoryLocation)
                            <option value="{{ $row->inventory_location_id }}" @selected(old('inventory_location_id') == $row->inventory_location_id)>
                                {{ $row->inventoryLocation->name }} — {{ format_quantity_id($locStock) }} tersedia
                            </option>
                        @endif
                    @endforeach
                </select>
                @error('inventory_location_id')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="disposal-type-{{ $batch->id }}" class="text-sm font-medium text-gray-700">Jenis Permintaan</label>
                <select id="disposal-type-{{ $batch->id }}" name="request_type" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">Pilih jenis…</option>
                    @foreach (InventoryBatchDisposalRequestType::values() as $type)
                        <option value="{{ $type }}" @selected(old('request_type') === $type)>{{ InventoryBatchDisposalRequestType::label($type) }}</option>
                    @endforeach
                </select>
                @error('request_type')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="disposal-qty-{{ $batch->id }}" class="text-sm font-medium text-gray-700">Jumlah Diminta</label>
                <input type="number" step="0.0001" min="0.0001" id="disposal-qty-{{ $batch->id }}" name="quantity_requested" value="{{ old('quantity_requested') }}" required
                       class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                @error('quantity_requested')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="disposal-evidence-{{ $batch->id }}" class="text-sm font-medium text-gray-700">Catatan Bukti</label>
                <textarea id="disposal-evidence-{{ $batch->id }}" name="evidence_note" rows="3" required minlength="10" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" placeholder="Alasan, kondisi batch, dan bukti operasional…">{{ old('evidence_note') }}</textarea>
                @error('evidence_note')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="disposal-ref-{{ $batch->id }}" class="text-sm font-medium text-gray-700">Referensi Bukti (opsional)</label>
                <input type="text" id="disposal-ref-{{ $batch->id }}" name="evidence_reference" value="{{ old('evidence_reference') }}" maxlength="255"
                       class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" placeholder="No. BA / dokumen / tiket">
                @error('evidence_reference')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            @if ($latestActionLog ?? null)
                <input type="hidden" name="inventory_batch_action_log_id" value="{{ $latestActionLog->id }}">
            @endif
            <button type="submit" class="inline-flex justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                Ajukan Permintaan
            </button>
        </form>
    </section>
@endcan
