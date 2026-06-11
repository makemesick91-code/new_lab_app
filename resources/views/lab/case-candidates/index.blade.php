<x-settings-shell title="Kandidat Pekerjaan Lab">
    @php
        $statusLabels = [
            'pending_review'          => 'Menunggu Review',
            'converted_to_lab_order'  => 'Sudah Dikonversi',
            'rejected'                => 'Ditolak',
            'cancelled'               => 'Dibatalkan',
        ];
        $statusTones = [
            'pending_review'          => 'warning',
            'converted_to_lab_order'  => 'success',
            'rejected'                => 'danger',
            'cancelled'               => 'neutral',
        ];
        $hasFilter = !empty($filters['status']) || !empty($filters['search']);
    @endphp

    <div class="space-y-6">

        {{-- Page header --}}
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">RME → Lab</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Kandidat Pekerjaan Lab</h2>
            <p class="mt-1 text-sm text-gray-500">Daftar kandidat pekerjaan lab yang dihasilkan dari pembayaran RME.</p>
        </div>

        {{-- Filter bar --}}
        <x-ui.card padding="p-4">
            <form method="GET" action="{{ route('lab-case-candidates.index') }}">
                <div class="flex flex-wrap items-end gap-2">

                    <div class="min-w-[12rem] flex-1">
                        <label for="lcc-search" class="text-sm font-medium text-gray-700">Cari</label>
                        <input id="lcc-search" type="text" name="search"
                               value="{{ $filters['search'] ?? '' }}"
                               placeholder="Pasien, dokter, deskripsi tindakan…"
                               class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                    </div>

                    <div class="w-48">
                        <label for="lcc-status" class="text-sm font-medium text-gray-700">Status</label>
                        <select id="lcc-status" name="status"
                                class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                            <option value="">Semua status</option>
                            @foreach ($statusLabels as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-ui.button type="submit" variant="primary">Cari</x-ui.button>
                        @if ($hasFilter)
                            <x-ui.button variant="neutral" :href="route('lab-case-candidates.index')">Reset</x-ui.button>
                        @endif
                    </div>

                </div>
            </form>
        </x-ui.card>

        {{-- Result count --}}
        @if ($candidates->total() > 0)
            <p class="text-sm text-gray-500">
                Menampilkan <span class="font-medium text-gray-700">{{ $candidates->firstItem() }}–{{ $candidates->lastItem() }}</span>
                dari <span class="font-medium text-gray-700">{{ $candidates->total() }}</span> kandidat
                @if ($hasFilter)
                    <a href="{{ route('lab-case-candidates.index') }}" class="ml-2 text-teal-600 hover:underline">Hapus filter</a>
                @endif
            </p>
        @endif

        {{-- Table --}}
        <x-ui.card>
            <x-ui.table>
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">Tanggal</th>
                        <th scope="col" class="px-3 py-3 font-medium">Pasien / No RM</th>
                        <th scope="col" class="px-3 py-3 font-medium">Dokter</th>
                        <th scope="col" class="px-3 py-3 font-medium">Tindakan</th>
                        <th scope="col" class="px-3 py-3 font-medium text-right">Qty</th>
                        <th scope="col" class="px-3 py-3 font-medium text-right">Estimasi</th>
                        <th scope="col" class="px-3 py-3 font-medium">Status</th>
                        <th scope="col" class="px-3 py-3 font-medium">Lab Order</th>
                        <th scope="col" class="px-3 py-3 font-medium">Invoice</th>
                        <th scope="col" class="px-4 py-3 font-medium text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white text-sm">
                    @forelse ($candidates as $candidate)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
                                {{ $candidate->created_at?->format('d/m/Y') }}
                            </td>
                            <td class="px-3 py-3">
                                <div class="font-medium text-gray-900">{{ $candidate->patient?->name ?? '—' }}</div>
                                <div class="text-xs text-gray-500 font-mono">{{ $candidate->patient?->medical_record_number ?? '' }}</div>
                            </td>
                            <td class="px-3 py-3 text-gray-600">
                                {{ $candidate->doctor?->name ?? '—' }}
                            </td>
                            <td class="px-3 py-3 text-gray-600 max-w-xs truncate" title="{{ $candidate->source_description }}">
                                {{ $candidate->source_description ?? $candidate->treatment?->name ?? '—' }}
                            </td>
                            <td class="px-3 py-3 text-right text-gray-600">
                                {{ $candidate->quantity }}
                            </td>
                            <td class="px-3 py-3 text-right text-gray-600 whitespace-nowrap">
                                Rp {{ number_format((float) $candidate->estimated_price, 0, ',', '.') }}
                            </td>
                            <td class="px-3 py-3">
                                <x-ui.badge :tone="$candidate->statusTone()">
                                    {{ $candidate->statusLabel() }}
                                </x-ui.badge>
                            </td>
                            <td class="px-3 py-3 text-gray-600 font-mono text-xs whitespace-nowrap">
                                @if ($candidate->isConverted() && $candidate->convertedLabOrder)
                                    <a href="{{ route('lab-orders.show', $candidate->convertedLabOrder) }}"
                                       class="text-teal-700 hover:text-teal-900 hover:underline">
                                        {{ $candidate->convertedLabOrder->order_number }}
                                    </a>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-gray-600 font-mono text-xs">
                                {{ $candidate->rmeInvoice?->invoice_number ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <x-ui.button variant="secondary"
                                             :href="route('lab-case-candidates.show', $candidate)"
                                             class="!px-3 !py-1.5 !text-xs">
                                    Detail
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-12 text-center">
                                <p class="font-medium text-gray-700">Belum ada kandidat pekerjaan lab.</p>
                                <p class="mt-1 text-sm text-gray-500">
                                    Kandidat akan muncul setelah tagihan RME lunas dan item tindakan membutuhkan pekerjaan lab.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($candidates->hasPages())
                <div class="border-t border-gray-200 px-4 py-3">
                    {{ $candidates->links() }}
                </div>
            @endif
        </x-ui.card>

    </div>
</x-settings-shell>
