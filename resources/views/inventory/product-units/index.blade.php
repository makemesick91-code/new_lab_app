<x-settings-shell title="Satuan Produk">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Satuan Produk</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Satuan Produk Persediaan</h2>
                <p class="mt-1 text-sm text-gray-500">Kelola satuan global untuk produk persediaan tanpa mengubah ledger stok.</p>
            </div>
            @can('create', \App\Modules\Inventory\Models\ProductUnit::class)
                <a href="{{ route('inventory.product-units.create') }}" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Tambah Satuan
                </a>
            @endcan
        </div>

        <form method="GET" action="{{ route('inventory.product-units.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_auto_auto] md:items-end">
                <div>
                    <label for="unit-search" class="text-sm font-medium text-gray-700">Cari satuan</label>
                    <input id="unit-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Nama atau simbol satuan"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="unit-status" class="text-sm font-medium text-gray-700">Status aktif</label>
                    <select id="unit-status" name="is_active" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua satuan</option>
                        <option value="1" @selected(($filters['is_active'] ?? '') === true || ($filters['is_active'] ?? '') === '1')>Hanya aktif</option>
                        <option value="0" @selected(($filters['is_active'] ?? '') === false || ($filters['is_active'] ?? '') === '0')>Hanya nonaktif</option>
                    </select>
                </div>
                <button class="inline-flex justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-700 focus:ring-offset-2">
                    Terapkan
                </button>
                <a href="{{ route('inventory.product-units.index') }}" class="inline-flex justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Atur Ulang
                </a>
            </div>
        </form>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Satuan</h3>
                    <p class="text-sm text-gray-500">{{ format_number_id($units->total()) }} satuan produk tersedia.</p>
                </div>
                <span class="inline-flex rounded-full bg-sky-50 px-3 py-1 text-xs font-medium text-sky-700">Global Master</span>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-4 py-3 font-medium">Satuan</th>
                            <th scope="col" class="px-3 py-3 font-medium">Deskripsi</th>
                            <th scope="col" class="px-3 py-3 font-medium">Status</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($units as $unit)
                            <tr @class(['hover:bg-gray-50', 'bg-gray-50/60 text-gray-500' => ! $unit->is_active])>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-gray-900">{{ $unit->name }}</p>
                                    <p class="mt-0.5 text-xs text-gray-500">{{ $unit->symbol }}</p>
                                </td>
                                <td class="px-3 py-3 text-gray-600">{{ $unit->description ?: '-' }}</td>
                                <td class="px-3 py-3">@include('inventory._status-badge', ['active' => $unit->is_active])</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        @can('update', $unit)
                                            <a href="{{ route('inventory.product-units.edit', $unit) }}" class="font-medium text-teal-700 hover:text-teal-600">Edit</a>
                                        @endcan
                                        @can('delete', $unit)
                                            @if ($unit->is_active)
                                                <form method="POST" action="{{ route('inventory.product-units.destroy', $unit) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="font-medium text-rose-700 hover:text-rose-600">Nonaktifkan</button>
                                                </form>
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-12">
                                    <div class="mx-auto max-w-sm text-center">
                                        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                                            <span class="text-lg font-semibold">0</span>
                                        </div>
                                        <p class="mt-3 text-sm font-medium text-gray-900">Belum ada satuan produk.</p>
                                        <p class="mt-1 text-sm text-gray-500">Tambahkan satuan sebelum membuat produk persediaan.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-gray-100 md:hidden">
                @forelse ($units as $unit)
                    <article @class(['p-4', 'bg-gray-50/60' => ! $unit->is_active])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $unit->symbol }}</p>
                                <h3 class="mt-1 text-base font-semibold text-gray-900">{{ $unit->name }}</h3>
                                <p class="mt-1 text-sm text-gray-500">{{ $unit->description ?: 'Tanpa deskripsi.' }}</p>
                            </div>
                            <div class="shrink-0">@include('inventory._status-badge', ['active' => $unit->is_active])</div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @can('update', $unit)
                                <a href="{{ route('inventory.product-units.edit', $unit) }}" class="rounded-lg border border-teal-200 px-3 py-2 text-sm font-medium text-teal-700">Edit</a>
                            @endcan
                            @can('delete', $unit)
                                @if ($unit->is_active)
                                    <form method="POST" action="{{ route('inventory.product-units.destroy', $unit) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="rounded-lg border border-rose-200 px-3 py-2 text-sm font-medium text-rose-700">Nonaktifkan</button>
                                    </form>
                                @endif
                            @endcan
                        </div>
                    </article>
                @empty
                    <div class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-gray-900">Belum ada satuan produk.</p>
                        <p class="mt-1 text-sm text-gray-500">Tambahkan satuan sebelum membuat produk persediaan.</p>
                    </div>
                @endforelse
            </div>

            <div class="border-t border-gray-200 px-4 py-3">{{ $units->links() }}</div>
        </section>
    </div>
</x-settings-shell>
