<x-settings-shell title="Master Metode Pembayaran">
    @php($hasFilters = $filters['search'] || $filters['type'] || $filters['is_active'] !== null)

    <x-ui.filter-bar :action="route('settings.payment-methods.index')">
        <div class="w-full md:w-64">
            <x-ui.input name="search" :value="$filters['search']" placeholder="Cari nama atau kode" aria-label="Cari metode pembayaran" />
        </div>
        <div class="w-full md:w-48">
            <x-ui.select name="type" aria-label="Filter tipe">
                <option value="">Semua tipe</option>
                @foreach ($types as $type)
                    <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-48">
            <x-ui.select name="is_active" aria-label="Filter status">
                <option value="">Semua status</option>
                <option value="1" @selected($filters['is_active'] === true)>Aktif</option>
                <option value="0" @selected($filters['is_active'] === false)>Nonaktif</option>
            </x-ui.select>
        </div>
        <x-slot name="actions">
            <x-ui.button type="submit" size="sm">Terapkan</x-ui.button>
            @if ($hasFilters)
                <x-ui.button href="{{ route('settings.payment-methods.index') }}" variant="ghost" size="sm">Atur Ulang</x-ui.button>
            @endif
        </x-slot>
    </x-ui.filter-bar>

    <x-ui.card :padding="'p-0'">
        <div class="flex items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <h3 class="text-base font-semibold text-navy">Daftar Metode Pembayaran</h3>
            @can('create', \App\Modules\PaymentMethod\Models\PaymentMethod::class)
                <x-ui.button href="{{ route('settings.payment-methods.create') }}" size="sm">+ Tambah Metode</x-ui.button>
            @endcan
        </div>

        @if ($paymentMethods->isEmpty())
            <div class="p-5">
                <x-ui.empty-state title="Belum ada metode pembayaran" description="Tambahkan metode pembayaran yang tersedia di kasir.">
                    @can('create', \App\Modules\PaymentMethod\Models\PaymentMethod::class)
                        <x-slot name="action">
                            <x-ui.button href="{{ route('settings.payment-methods.create') }}" size="sm">+ Tambah Metode</x-ui.button>
                        </x-slot>
                    @endcan
                </x-ui.empty-state>
            </div>
        @else
            <x-ui.table>
                <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Kode</th>
                        <th class="px-4 py-3 font-semibold">Nama</th>
                        <th class="px-4 py-3 font-semibold">Tipe</th>
                        <th class="px-4 py-3 font-semibold">Urutan</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($paymentMethods as $method)
                        <tr class="hover:bg-navy-50/60">
                            <td class="px-4 py-3 text-ink-soft">{{ $method->code }}</td>
                            <td class="px-4 py-3 font-medium text-navy">{{ $method->name }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ ucfirst(str_replace('_', ' ', $method->type)) }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $method->sort_order }}</td>
                            <td class="px-4 py-3">
                                <x-ui.badge :tone="$method->is_active ? 'success' : 'neutral'">{{ $method->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $method)
                                        <x-ui.button href="{{ route('settings.payment-methods.edit', $method) }}" variant="secondary" size="sm">Ubah</x-ui.button>
                                    @endcan
                                    @can('delete', $method)
                                        <form method="POST" action="{{ route('settings.payment-methods.destroy', $method) }}" onsubmit="return confirm('Hapus metode pembayaran ini?');">
                                            @csrf @method('DELETE')
                                            <x-ui.button type="submit" variant="danger" size="sm">Hapus</x-ui.button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <div>{{ $paymentMethods->links() }}</div>
</x-settings-shell>
