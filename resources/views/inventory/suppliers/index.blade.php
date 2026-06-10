<x-settings-shell title="Pemasok Persediaan">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Pemasok Persediaan</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Direktori Pemasok</h2>
                <p class="mt-1 text-sm text-gray-500">Kelola pemasok untuk operasi pengadaan dan penerimaan stok.</p>
            </div>
            <x-ui.button variant="primary" :href="route('inventory.suppliers.create')">Tambah Pemasok</x-ui.button>
        </div>

        <x-ui.card padding="p-4">
            <form method="GET" action="{{ route('inventory.suppliers.index') }}">
                <div class="flex flex-wrap items-end gap-2">
                    <div class="min-w-[12rem] flex-1">
                        <label for="supplier-search" class="text-sm font-medium text-gray-700">Cari pemasok</label>
                        <input id="supplier-search" type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Nama pemasok"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    </div>
                    <x-ui.button type="submit" variant="neutral">Cari</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('inventory.suppliers.index')">Atur Ulang</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padding="">
            <div class="border-b border-gray-200 px-4 py-3">
                <h3 class="text-base font-semibold text-gray-900">Pemasok</h3>
                <p class="text-sm text-gray-500">{{ format_number_id($suppliers->total()) }} pemasok dalam cabang aktif.</p>
            </div>

            <x-ui.table class="!border-0 !shadow-none !rounded-none">
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">Nama</th>
                        <th scope="col" class="px-3 py-3 font-medium">Telepon</th>
                        <th scope="col" class="px-3 py-3 font-medium">Email</th>
                        <th scope="col" class="px-3 py-3 font-medium">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($suppliers as $supplier)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $supplier->name }}</td>
                            <td class="px-3 py-3 text-gray-600">{{ $supplier->phone ?? '-' }}</td>
                            <td class="px-3 py-3 text-gray-600">{{ $supplier->email ?? '-' }}</td>
                            <td class="px-3 py-3">@include('inventory._status-badge', ['active' => $supplier->is_active])</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    <x-ui.button variant="secondary" :href="route('inventory.suppliers.show', $supplier)" class="!px-3 !py-1.5 !text-xs">Lihat</x-ui.button>
                                    <x-ui.button variant="primary" :href="route('inventory.suppliers.edit', $supplier)" class="!px-3 !py-1.5 !text-xs">Ubah</x-ui.button>
                                    @if ($supplier->is_active)
                                        <form method="POST" action="{{ route('inventory.suppliers.destroy', $supplier) }}">
                                            @csrf @method('DELETE')
                                            <x-ui.button type="submit" variant="danger" class="!px-3 !py-1.5 !text-xs">Nonaktifkan</x-ui.button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center">
                                <p class="text-sm font-medium text-gray-900">Belum ada pemasok.</p>
                                <p class="mt-1 text-sm text-gray-500">Tambahkan pemasok untuk mendukung alur pengadaan.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            <div class="border-t border-gray-200 px-4 py-3">{{ $suppliers->links() }}</div>
        </x-ui.card>
    </div>
</x-settings-shell>
