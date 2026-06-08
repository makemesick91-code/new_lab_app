<x-settings-shell title="Master Metode Pembayaran">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="GET" action="{{ route('settings.payment-methods.index') }}" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Cari nama atau kode"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <select name="type" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua tipe</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                        @endforeach
                    </select>
                    <select name="is_active" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua status</option>
                        <option value="1" @selected($filters['is_active'] === true)>Aktif</option>
                        <option value="0" @selected($filters['is_active'] === false)>Nonaktif</option>
                    </select>
                    <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                    @if ($filters['search'] || $filters['type'] || $filters['is_active'] !== null)
                        <a href="{{ route('settings.payment-methods.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
                    @endif
                </form>
                @can('create', \App\Modules\PaymentMethod\Models\PaymentMethod::class)
                    <a href="{{ route('settings.payment-methods.create') }}" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">+ Tambah Metode</a>
                @endcan
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Kode</th>
                            <th class="px-3 py-2 font-medium">Nama</th>
                            <th class="px-3 py-2 font-medium">Tipe</th>
                            <th class="px-3 py-2 font-medium">Urutan</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($paymentMethods as $method)
                            <tr>
                                <td class="px-3 py-2 text-gray-600">{{ $method->code }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $method->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ ucfirst(str_replace('_', ' ', $method->type)) }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $method->sort_order }}</td>
                                <td class="px-3 py-2">
                                    @if ($method->is_active)
                                        <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">Aktif</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Nonaktif</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-2">
                                        @can('update', $method)
                                            <a href="{{ route('settings.payment-methods.edit', $method) }}" class="text-indigo-600 hover:text-indigo-500">Ubah</a>
                                        @endcan
                                        @can('delete', $method)
                                            <form method="POST" action="{{ route('settings.payment-methods.destroy', $method) }}" onsubmit="return confirm('Hapus metode pembayaran ini?');">@csrf @method('DELETE')<button class="text-red-600 hover:text-red-500">Hapus</button></form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada metode pembayaran.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $paymentMethods->links() }}</div>
        </div>
    </div>
</x-settings-shell>
