@php
    use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestStatus;
    use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestType;
@endphp

<x-settings-shell title="Permintaan Disposal/Adjustment Batch">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Workflow Disposal & Adjustment</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Permintaan Disposal/Adjustment</h2>
                <p class="mt-1 text-sm text-gray-500">Cabang aktif — pengajuan tidak mengubah stok sampai finalisasi ADJUSTMENT_OUT.</p>
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">
                {{ session('status') }}
            </div>
        @endif

        <form method="GET" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="filter-status" class="text-sm font-medium text-gray-700">Status</label>
                    <select id="filter-status" name="status" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                        <option value="">Semua</option>
                        @foreach (InventoryBatchDisposalRequestStatus::values() as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ InventoryBatchDisposalRequestStatus::label($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="filter-type" class="text-sm font-medium text-gray-700">Jenis</label>
                    <select id="filter-type" name="request_type" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                        <option value="">Semua</option>
                        @foreach (InventoryBatchDisposalRequestType::values() as $type)
                            <option value="{{ $type }}" @selected(($filters['request_type'] ?? '') === $type)>{{ InventoryBatchDisposalRequestType::label($type) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="filter-search" class="text-sm font-medium text-gray-700">Cari batch/produk</label>
                    <input type="search" id="filter-search" name="search" value="{{ $filters['search'] ?? '' }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-600">Filter</button>
                </div>
            </div>
        </form>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-4 py-3 font-medium">Tanggal</th>
                            <th scope="col" class="px-3 py-3 font-medium">Batch</th>
                            <th scope="col" class="px-3 py-3 font-medium">Produk</th>
                            <th scope="col" class="px-3 py-3 font-medium">Lokasi</th>
                            <th scope="col" class="px-3 py-3 font-medium">Jenis</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Jumlah</th>
                            <th scope="col" class="px-3 py-3 font-medium">Status</th>
                            <th scope="col" class="px-3 py-3 font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($requests as $item)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-600">{{ format_datetime_id($item->submitted_at ?? $item->created_at) }}</td>
                                <td class="px-3 py-3 font-medium text-gray-900">{{ $item->batch?->batch_number ?? '—' }}</td>
                                <td class="px-3 py-3 text-gray-700">{{ $item->product?->name ?? '—' }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $item->location?->name ?? '—' }}</td>
                                <td class="px-3 py-3">@include('inventory.batch-disposal-requests._request-type-badge', ['requestType' => $item->request_type])</td>
                                <td class="px-3 py-3 text-right tabular-nums text-gray-900">{{ format_quantity_id((float) $item->quantity_requested) }}</td>
                                <td class="px-3 py-3">@include('inventory.batch-disposal-requests._status-badge', ['status' => $item->status])</td>
                                <td class="px-3 py-3">
                                    <a href="{{ route('inventory.batch-disposal-requests.show', $item) }}" class="font-medium text-teal-700 hover:text-teal-600">Detail</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-10 text-center text-gray-500">Belum ada permintaan disposal/adjustment.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($requests->hasPages())
                <div class="border-t border-gray-200 px-4 py-3">{{ $requests->links() }}</div>
            @endif
        </section>
    </div>
</x-settings-shell>
