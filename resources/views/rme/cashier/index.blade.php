<x-settings-shell title="Kasir — Kunjungan Menunggu Pembayaran">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Daftar Kunjungan Siap Ditagih</h3>
            </div>

            <form method="GET" class="flex gap-2">
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                    placeholder="Cari nomor kunjungan atau nama pasien..."
                    class="flex-1 rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <button type="submit"
                    class="inline-flex items-center px-4 py-2 bg-gray-600 text-white text-sm font-medium rounded-md hover:bg-gray-500">
                    Cari
                </button>
            </form>

            @if ($visits->isEmpty())
                <p class="text-sm text-gray-500 py-4 text-center">Tidak ada kunjungan yang menunggu tagihan.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">No. Kunjungan</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Pasien</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Dokter</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Layanan Awal</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Status Tagihan</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($visits as $visit)
                                @php
                                    $invoice = $visit->rmeInvoice ?? null;
                                    $hasInvoice = $invoice !== null;
                                @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $visit->visit_number }}</td>
                                    <td class="px-4 py-3 text-gray-900">{{ $visit->patient?->name }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ $visit->doctor?->name }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ $visit->initialTreatment?->name ?? '-' }}</td>
                                    <td class="px-4 py-3">
                                        @if ($hasInvoice)
                                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-amber-50 text-amber-700">
                                                {{ $invoice->status }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-gray-100 text-gray-600">
                                                Belum ditagih
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($hasInvoice)
                                            <a href="{{ route('rme.cashier.show', [$visit, $invoice]) }}"
                                                class="text-indigo-600 hover:text-indigo-500 text-sm font-medium">Lihat Tagihan</a>
                                        @else
                                            <a href="{{ route('rme.cashier.create', $visit) }}"
                                                class="inline-flex items-center px-3 py-1 bg-indigo-600 text-white text-xs font-medium rounded hover:bg-indigo-500">
                                                Buat Tagihan
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $visits->links() }}
                </div>
            @endif
        </div>
    </div>
</x-settings-shell>
