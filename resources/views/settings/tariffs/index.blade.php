<x-settings-shell title="Master Tarif">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="GET" action="{{ route('settings.tariffs.index') }}" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Cari kode atau nama perawatan"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <select name="treatment_id" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua perawatan</option>
                        @foreach ($treatments as $treatment)
                            <option value="{{ $treatment->id }}" @selected($filters['treatment_id'] === $treatment->id)>{{ $treatment->code }} — {{ $treatment->name }}</option>
                        @endforeach
                    </select>
                    <select name="is_active" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua status</option>
                        <option value="1" @selected($filters['is_active'] === true)>Aktif</option>
                        <option value="0" @selected($filters['is_active'] === false)>Nonaktif</option>
                    </select>
                    <select name="requires_lab" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua lab</option>
                        <option value="1" @selected($filters['requires_lab'] === true)>Perlu Lab</option>
                        <option value="0" @selected($filters['requires_lab'] === false)>Tanpa Lab</option>
                    </select>
                    <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                    @if ($filters['search'] || $filters['treatment_id'] || $filters['is_active'] !== null || $filters['requires_lab'] !== null)
                        <a href="{{ route('settings.tariffs.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
                    @endif
                </form>
                @can('create', \App\Modules\Tariff\Models\Tariff::class)
                    <a href="{{ route('settings.tariffs.create') }}" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">+ Tambah Tarif</a>
                @endcan
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Kode</th>
                            <th class="px-3 py-2 font-medium">Perawatan</th>
                            <th class="px-3 py-2 font-medium">Kategori</th>
                            <th class="px-3 py-2 font-medium">Lab</th>
                            <th class="px-3 py-2 font-medium text-right">Harga</th>
                            <th class="px-3 py-2 font-medium">Berlaku</th>
                            <th class="px-3 py-2 font-medium">Cabang</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($tariffs as $tariff)
                            <tr>
                                <td class="px-3 py-2 text-gray-600">{{ $tariff->treatment?->code }}</td>
                                <td class="px-3 py-2">
                                    <div class="font-medium text-gray-900">{{ $tariff->treatment?->name ?? '—' }}</div>
                                    @if ($tariff->notes)
                                        <div class="text-xs text-gray-400">{{ $tariff->notes }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ $tariff->treatment?->category?->name ?? '—' }}</span>
                                </td>
                                <td class="px-3 py-2">
                                    @if ($tariff->treatment?->requires_lab)
                                        <span class="inline-flex items-center rounded-full bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-700">Perlu Lab</span>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right font-medium text-gray-900">Rp {{ number_format((float) $tariff->price, 0, ',', '.') }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $tariff->effective_date?->format('d/m/Y') }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $tariff->branch?->name ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    @if ($tariff->is_active)
                                        <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">Aktif</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Nonaktif</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-2">
                                        @can('update', $tariff)
                                            <a href="{{ route('settings.tariffs.edit', $tariff) }}" class="text-indigo-600 hover:text-indigo-500">Ubah</a>
                                        @endcan
                                        @can('delete', $tariff)
                                            <form method="POST" action="{{ route('settings.tariffs.destroy', $tariff) }}" onsubmit="return confirm('Hapus tarif ini?');">@csrf @method('DELETE')<button class="text-red-600 hover:text-red-500">Hapus</button></form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">Belum ada tarif.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $tariffs->links() }}</div>
        </div>
    </div>
</x-settings-shell>
