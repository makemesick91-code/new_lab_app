<x-settings-shell title="Kandidat Pekerjaan Lab">
    @php
        $statusLabels = [
            'pending_review'          => 'Menunggu Review',
            'converted_to_lab_order'  => 'Sudah Dikonversi',
            'rejected'                => 'Ditolak',
            'cancelled'               => 'Dibatalkan',
        ];
        $hasFilter = !empty($filters['status']) || !empty($filters['search']);
    @endphp

    <x-ui.page-header title="Kandidat Pekerjaan Lab" subtitle="Daftar kandidat pekerjaan lab yang dihasilkan dari pembayaran RME.">
        <x-slot:breadcrumb>RME → Lab</x-slot:breadcrumb>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('lab-case-candidates.index')">
        <x-ui.input name="search" :value="$filters['search'] ?? ''" placeholder="Pasien, dokter, deskripsi tindakan…" label="Cari" class="md:w-72" />
        <x-ui.select name="status" label="Status">
            <option value="">Semua status</option>
            @foreach ($statusLabels as $value => $label)
                <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </x-ui.select>
        <x-slot:actions>
            <x-ui.button type="submit">Cari</x-ui.button>
            @if ($hasFilter)
                <x-ui.button variant="secondary" :href="route('lab-case-candidates.index')">Reset</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.filter-bar>

    @if ($candidates->total() > 0)
        <p class="mb-3 text-sm text-ink-soft">
            Menampilkan <span class="font-medium text-ink">{{ $candidates->firstItem() }}–{{ $candidates->lastItem() }}</span>
            dari <span class="font-medium text-ink">{{ $candidates->total() }}</span> kandidat
            @if ($hasFilter)
                <a href="{{ route('lab-case-candidates.index') }}" class="ml-2 text-brand-600 hover:underline">Hapus filter</a>
            @endif
        </p>
    @endif

    <x-ui.table>
        <thead class="bg-navy-50 text-ink">
            <tr>
                <th scope="col" class="px-4 py-3 text-left font-medium">Tanggal</th>
                <th scope="col" class="px-3 py-3 text-left font-medium">Pasien / No RM</th>
                <th scope="col" class="px-3 py-3 text-left font-medium">Dokter</th>
                <th scope="col" class="px-3 py-3 text-left font-medium">Tindakan</th>
                <th scope="col" class="px-3 py-3 text-right font-medium">Qty</th>
                <th scope="col" class="px-3 py-3 text-right font-medium">Estimasi</th>
                <th scope="col" class="px-3 py-3 text-left font-medium">Status</th>
                <th scope="col" class="px-3 py-3 text-left font-medium">Lab Order</th>
                <th scope="col" class="px-3 py-3 text-left font-medium">Invoice</th>
                <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-hairline text-sm">
            @forelse ($candidates as $candidate)
                <tr class="transition-colors hover:bg-navy-50">
                    <td class="whitespace-nowrap px-4 py-3 text-ink-soft">{{ $candidate->created_at?->format('d/m/Y') }}</td>
                    <td class="px-3 py-3">
                        <div class="font-medium text-navy">{{ $candidate->patient?->name ?? '—' }}</div>
                        <div class="font-mono text-xs text-ink-soft">{{ $candidate->patient?->medical_record_number ?? '' }}</div>
                    </td>
                    <td class="px-3 py-3 text-ink-soft">{{ $candidate->doctor?->name ?? '—' }}</td>
                    <td class="max-w-xs truncate px-3 py-3 text-ink-soft" title="{{ $candidate->source_description }}">
                        {{ $candidate->source_description ?? $candidate->treatment?->name ?? '—' }}
                    </td>
                    <td class="px-3 py-3 text-right text-ink-soft">{{ $candidate->quantity }}</td>
                    <td class="whitespace-nowrap px-3 py-3 text-right text-ink-soft">
                        Rp {{ number_format((float) $candidate->estimated_price, 0, ',', '.') }}
                    </td>
                    <td class="px-3 py-3">
                        <x-ui.badge :tone="$candidate->statusTone()">{{ $candidate->statusLabel() }}</x-ui.badge>
                    </td>
                    <td class="whitespace-nowrap px-3 py-3 font-mono text-xs text-ink-soft">
                        @if ($candidate->isConverted() && $candidate->convertedLabOrder)
                            <a href="{{ route('lab-orders.show', $candidate->convertedLabOrder) }}"
                               class="text-brand-700 hover:text-brand-800 hover:underline">
                                {{ $candidate->convertedLabOrder->order_number }}
                            </a>
                        @else
                            <span class="text-ink-muted">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-3 font-mono text-xs text-ink-soft">{{ $candidate->rmeInvoice?->invoice_number ?? '—' }}</td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.button size="sm" variant="secondary" :href="route('lab-case-candidates.show', $candidate)">Detail</x-ui.button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="px-4 py-6">
                        <x-ui.empty-state title="Belum ada kandidat pekerjaan lab"
                                          description="Kandidat akan muncul setelah tagihan RME lunas dan item tindakan membutuhkan pekerjaan lab." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    @if ($candidates->hasPages())
        <div class="mt-4">{{ $candidates->links() }}</div>
    @endif
</x-settings-shell>
