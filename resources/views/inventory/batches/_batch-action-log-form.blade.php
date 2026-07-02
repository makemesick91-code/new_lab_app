@php
    use App\Modules\Inventory\Enums\InventoryBatchActionType;
    use App\Modules\Inventory\Models\InventoryBatch;
@endphp

@can('recordAction', $batch)
    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm" id="catat-tindakan-batch">
        <h3 class="text-base font-semibold text-gray-900">Catat Tindakan</h3>
        <p class="mt-1 text-sm text-amber-800">
            Catatan tindakan tidak mengubah stok. Pengurangan stok tetap harus melalui proses adjustment/opname resmi.
        </p>

        <form method="POST" action="{{ route('inventory.batches.action-logs.store', $batch) }}" class="mt-4 space-y-4">
            @csrf
            <div>
                <label for="batch-action-type-{{ $batch->id }}" class="text-sm font-medium text-gray-700">Jenis Tindakan</label>
                <select id="batch-action-type-{{ $batch->id }}" name="action_type" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">Pilih tindakan…</option>
                    @foreach (InventoryBatchActionType::values() as $type)
                        <option value="{{ $type }}" @selected(old('action_type') === $type)>{{ InventoryBatchActionType::label($type) }}</option>
                    @endforeach
                </select>
                @error('action_type')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="batch-action-note-{{ $batch->id }}" class="text-sm font-medium text-gray-700">Catatan (opsional)</label>
                <textarea id="batch-action-note-{{ $batch->id }}" name="note" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" placeholder="Detail tindakan operasional…">{{ old('note') }}</textarea>
                @error('note')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="inline-flex justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                Simpan Tindakan
            </button>
        </form>
    </section>
@endcan
